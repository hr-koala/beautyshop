<?php
namespace app\cosmetology\controller;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
class Index extends Base {
    //首页
    public function index(){ 
    	$hot_area = $this->hot_area();//热卖专区
    	$this->assign('hot_area',$hot_area);
        return $this->fetch();
    }

    //首页1
    public function index1(){
    	$recommend_area = $this->recommend_area();//推荐专区
    	$hot_area = $this->hot_area();//热卖专区
        $this->assign('hot_area',$hot_area);
        return $this->fetch();
    }


}