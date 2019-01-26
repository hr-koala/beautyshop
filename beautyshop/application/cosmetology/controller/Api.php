<?php
namespace app\cosmetology\controller;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
class Api extends Base {
	//首页接口
	public function index(){
		$info['hot_area'] = $this->hot_area();//热卖专区
		$info['rotation_chart'] = $this->rotation_chart();//轮播图
		echo json_encode(['msg'=>'成功','staus'=>1,'info'=>$info]);
	}
}