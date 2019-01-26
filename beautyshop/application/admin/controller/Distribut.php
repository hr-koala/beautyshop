<?php

namespace app\admin\controller;
use think\AjaxPage;
use think\Page;
use think\Db;
use think\Loader;

class Distribut extends Base {
    //分销商品列表
    public function goods_list(){
        return $this->fetch();
    }
    //分成日志
    public function rebate_log(){
        return $this->fetch();
    }
}