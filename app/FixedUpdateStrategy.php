<?php

namespace App;

use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;

class FixedUpdateStrategy extends \Humbug\SelfUpdate\Strategy\GithubStrategy implements StrategyInterface
{
    public function __construct()
    {
        $this->setPharName(basename(\Phar::running()));
    }

}
