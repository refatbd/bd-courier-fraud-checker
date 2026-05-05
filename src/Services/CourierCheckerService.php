<?php

namespace Refatbd\BdCourierFraudChecker\Services;

use Refatbd\BdCourierFraudChecker\Courier\Pathao;
use Refatbd\BdCourierFraudChecker\Courier\Redx;
use Refatbd\BdCourierFraudChecker\Courier\Steadfast;

class CourierCheckerService
{
    protected $steadfast;
    protected $pathao;
    protected $redx;

    public function __construct(Steadfast $steadfast, Pathao $pathao, Redx $redx)
    {
        $this->steadfast = $steadfast;
        $this->pathao = $pathao;
        $this->redx = $redx;
    }

    public function check($phone)
    {
        return [
            'steadfast' => $this->steadfast->steadfast($phone),
            'pathao' => $this->pathao->pathao($phone),
            'redx' => $this->redx->redx($phone),
        ];
    }
}
