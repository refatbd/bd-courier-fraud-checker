<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Facade;
use Refatbd\BdCourierFraudChecker\Courier\Redx;

$container->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore));
$body = ['code'=>200, 'isError'=>false, 'message'=>'', 'data'=>[
    'totalParcels'=>10, 'deliveredParcels'=>6, 'returnPercentage'=>4, 'customerSegment'=>'Normal Customer',
]];
$cases = [
    [200,$body,true,1], [400,$body,true,1],
    [400,array_replace($body,['isError'=>true]),false,1],
    [400,array_diff_key($body,['isError'=>true]),false,1],
    [400,array_replace($body,['code'=>400]),false,1],
    [200,array_replace($body,['isError'=>true]),false,1],
    [200,['data'=>[]],false,1],
    [401,$body,false,2], [403,$body,false,1], [429,$body,false,1], [500,$body,false,1],
];
foreach ([[-1,0],[1,2],[1.5,1],['invalid',0],[null,0]] as [$total,$delivered]) {
    $bad=$body; $bad['data']['totalParcels']=$total; $bad['data']['deliveredParcels']=$delivered;
    $cases[]=[400,$bad,false,1];
}
foreach ($cases as [$status,$payload,$expected,$requests]) {
    $container->instance(Factory::class,new Factory);
    Facade::clearResolvedInstance(Factory::class);
    Http::preventStrayRequests();
    Http::fake(['redx.com.bd/api/*'=>Http::response($payload,$status)]);
    $driver=new class extends Redx {
        public function __construct() {}
        public function getAccessToken() { return 'fake-token'; }
    };
    $result=$driver->redx('01700000000');
    assert($result['status']===$expected, 'Unexpected RedX acceptance for HTTP '.$status);
    assert($result['meta']['http_status']===$status);
    if ($expected) {
        assert($result['data']['total']===10 && $result['data']['success']===6);
        assert($result['data']['deliveredPercentage']===60.0);
        assert($result['data']['returnPercentage']===40.0);
        assert($result['data']['providerReturnPercentage']===4.0);
        assert($result['meta']['success_envelope_compatibility']===($status===400));
    } else {
        assert(trim($result['message'])!=='');
        assert($result['code']===$status);
    }
    assert(strpos(json_encode($result),'fake-token')===false);
    Http::assertSentCount($requests);
}
$container->instance(Factory::class,new Factory);
Facade::clearResolvedInstance(Factory::class);
Http::fake(fn()=>throw new Illuminate\Http\Client\ConnectionException('private token URL'));
assert($driver->redx('01700000000')['meta']['state']==='network_error');
$ref=new ReflectionClass(Refatbd\BdCourierFraudChecker\Courier\Paperfly::class);
$method=$ref->getMethod('formatResult'); $method->setAccessible(true);
$empty=$method->invoke($ref->newInstanceWithoutConstructor(),['total'=>0,'smart_check'=>['delivery_rate'=>0]]);
assert($empty['delivery_rate']===null && $empty['return_rate']===null);
echo "RedX success-envelope, rejection, request-count, redaction and Paperfly empty-rate checks passed.\n";
