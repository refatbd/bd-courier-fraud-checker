<?php

namespace Refatbd\BdCourierFraudChecker\Services;

use Refatbd\BdCourierFraudChecker\Courier\Carrybee;
use Refatbd\BdCourierFraudChecker\Courier\Pathao;
use Refatbd\BdCourierFraudChecker\Courier\Redx;
use Refatbd\BdCourierFraudChecker\Courier\Steadfast;

class CourierCheckerService
{
    protected $steadfast;
    protected $pathao;
    protected $redx;
    protected $carrybee;

    public function __construct(Steadfast $steadfast, Pathao $pathao, Redx $redx, Carrybee $carrybee)
    {
        $this->steadfast = $steadfast;
        $this->pathao    = $pathao;
        $this->redx      = $redx;
        $this->carrybee  = $carrybee;
    }

    public function check($phone)
    {
        return [
            'steadfast' => $this->steadfast->steadfast($phone),
            'pathao'    => $this->pathao->pathao($phone),
            'redx'      => $this->redx->redx($phone),
            'carrybee'  => $this->carrybee->carrybee($phone),
        ];
    }
}
