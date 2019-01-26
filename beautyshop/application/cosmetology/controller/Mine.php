<?php
namespace app\cosmetology\controller;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
class Mine extends Base {
	public function index(){
		$order = M('order');
		$where = 'user_id = '.$this->user_id;
		//待付款
		$order_num['WAITPAY'] = $this->getOrdernum($order,$where.C("WAITPAY"));
		//待发货
		$order_num['WAITSEND'] = $this->getOrdernum($order,$where.C("WAITSEND"));
		//待收货
		$order_num['WAITRECEIVE'] = $this->getOrdernum($order,$where.C("WAITRECEIVE"));
		//已完成
		$order_num['FINISH'] = $this->getOrdernum($order,$where.C("FINISH"));
		//$this->distribution('201812260947356875');
		$this->assign('order_num',$order_num);
		return $this->fetch();
	}

	public function getOrdernum($order,$where){
		return $order_num = $order->where($where)->count();
	}

	//设置页面
	public function setUp(){
		return $this->fetch();
	}

	//我的收藏
	public function collection(){
		$goods_collect = M('goods_collect');
		$collect_info = $goods_collect->where(['user_id'=>$this->user_id])->getField('collect_id,goods_id');
		$goods_id_str = implode(',', $collect_info);

		$where = ['is_on_sale'=>1 , 'goods_id'=>['in',$goods_id_str] , 'is_virtual' => ['exp' ,"=0 or virtual_indate > ".time()]];
	 	$goods = $this->select_goods($where,'sort ASC');
	 	$this->assign('goods',$goods);
		return $this->fetch();
	}

	//移除收藏
	public function remove_collection(){
		if(IS_AJAX){
			$_POST['user_id'] = $this->user_id;
			$res = M('goods_collect')->where($_POST)->delete();
			if($res){
                echo json_encode(['status'=>1,'msg'=>'添加成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'添加失败']);exit;
            }
		}
	}

	//地址管理
	public function address(){
		$user_address = M('user_address');
		$info = $user_address->where(['user_id'=>$this->user_id])->order('is_default desc')->select();

		if ($info) {
            $area_id = array();
            foreach ($info as $val) {
                $area_id[] = $val['province'];
                $area_id[] = $val['city'];
                $area_id[] = $val['district'];
                $area_id[] = $val['twon'];
            }
            $area_id = array_filter($area_id);
            $area_id = implode(',', $area_id);
            $regionList = Db::name('region')->where("id", "in", $area_id)->getField('id,name');
            $this->assign('regionList', $regionList);
        }
		$this->assign('info',$info);
		$url = I('get.url');
		if(empty($url)){ $url = 1; }else{ $url = cookie('firm_order_url'); }
		$this->assign('url', $url);
		return $this->fetch();
	}

	//删除地址
	public function deleteAddress(){
		if(IS_AJAX){
			$_POST['user_id'] = $this->user_id;
			$res = M('user_address')->where($_POST)->delete();
			if($res !== false){
                echo json_encode(['status'=>1,'msg'=>'删除成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'删除失败']);exit;
            }
		}
	}

	//设置默认地址
	public function defaultAddress(){
		if(IS_AJAX){
			$user_address = M('user_address');
			$_POST['user_id'] = $this->user_id;
			$user_address->where(['user_id'=>$this->user_id,'is_default'=>1])->save(['is_default'=>0]);
			$res = $user_address->where($_POST)->save(['is_default'=>1]);
			if($res !== false){
                echo json_encode(['status'=>1,'msg'=>'成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'失败']);exit;
            }
		}
	}

	//修改资料
	public function modifyingData(){
		return $this->fetch();
	}

	//上传图像
	public function uploadPictures(){
		if(IS_AJAX){
			$image = I('post.base');
			$imageName = "25220".date("His",time()).rand(1111,9999).'.png';
            if (strstr($image,",")){
                $image = explode(',',$image);
                $image = $image[1];
            }
            $path = "public/upload/head_portrait/".date("Ymd",time());
            if (!is_dir($path)){ //判断目录是否存在 不存在就创建
                mkdir($path,0777,true);
            }
            $imageSrc=  $path."/". $imageName;  //图片名字 "public/".
            //返回的是字节数
            $r = file_put_contents(str_replace("\\","/",ROOT_PATH) .$imageSrc, base64_decode($image));
            if (!$r) {
                echo json_encode(['status'=>0,'msg'=>'失败']);exit;
            }else{
            	$head_pic = "/".$imageSrc;
            	M('users')->where(['user_id'=>$this->user_id])->save(['head_pic'=>$head_pic]);
                echo json_encode(['status'=>1,'msg'=>'成功']);exit;
            }
		}
	}

	//修改昵称
	public function modifyNickname(){
		if(IS_AJAX){
			$nickname = I('post.nickname');
			if(!empty($nickname)){
				M('users')->where(['user_id'=>$this->user_id])->save(['nickname'=>$nickname]);
			}
		}
	}

	//关于我们
	public function aboutUs(){
		return $this->fetch();
	}






}