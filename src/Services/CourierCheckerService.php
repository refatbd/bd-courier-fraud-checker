<?php

namespace Refatbd\BdCourierFraudChecker\Services;

use Refatbd\BdCourierFraudChecker\Courier\Carrybee;
use Refatbd\BdCourierFraudChecker\Courier\Paperfly;
use Refatbd\BdCourierFraudChecker\Courier\Pathao;
use Refatbd\BdCourierFraudChecker\Courier\Redx;
use Refatbd\BdCourierFraudChecker\Courier\Steadfast;

class CourierCheckerService
{
    protected $steadfast;
    protected $pathao;
    protected $redx;
    protected $carrybee;
    protected $paperfly;

    public function __construct(Steadfast $steadfast, Pathao $pathao, Redx $redx, Carrybee $carrybee, Paperfly $paperfly)
    {
        $this->steadfast = $steadfast;
        $this->pathao    = $pathao;
        $this->redx      = $redx;
        $this->carrybee  = $carrybee;
        $this->paperfly  = $paperfly;
    }

    public function check($phone)
    {
        return [
            'steadfast' => $this->steadfast->steadfast($phone),
            'pathao'    => $this->pathao->pathao($phone),
            'redx'      => $this->redx->redx($phone),
            'carrybee'  => $this->carrybee->carrybee($phone),
            'paperfly'  => $this->paperfly->paperfly($phone),
        ];
    }
}
