<?php
namespace app\cosmetology\controller;
use app\common\logic\CartLogic;
use app\common\logic\Pay;
use app\common\logic\PlaceOrder;
use app\common\logic\CouponLogic;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
class Cart extends Base {
	//购物车列表
	public function CartList(){
		$user_id = $this->user_id;
		if($user_id > 0){
			$info = $this->getCartgoods($user_id);
	        $this->assign('total', $info['total']);//购物车总价格和数量
	        $this->assign('cartList', $info['cartList']);//商品列表
			return $this->fetch();
		}else{
            $url = $_SERVER['HTTP_REFERER'].trim($_SERVER["REQUEST_URI"],'/');
			header("location:" . U('cosmetology/User/login',['url'=>$_SERVER["REQUEST_URI"]]));
		}
	}

	public function getCartgoods($user_id){
		$cartLogic = new CartLogic();
        $cartLogic->setUserId($user_id);
        $info['cartList'] = $cartLogic->getCartList();//用户购物车
        $total_num = $total_price = 0;
        foreach ($info['cartList'] as $k => $v) {
        	if($v['selected'] == 1){
        		$total_num += $v['goods_num'];
        		$total_price += ($v['goods_num']*$v['member_goods_price']);
        	}
        }
        $info['total']['num'] = $total_num;
        $info['total']['price'] = $total_price;
        return $info;
	}

	//购物车加商品数量
	public function addCart(){
		if(IS_AJAX){
			$_POST['user_id'] = $this->user_id;
			$res = M('cart')->where($_POST)->setInc('goods_num');
			if($res){
                echo json_encode(['status'=>1,'msg'=>'添加成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'添加失败']);exit;
            }
		}
	}

	//购物车减商品数量
	public function subCart(){
		if(IS_AJAX){
			$_POST['user_id'] = $this->user_id;
			$res = M('cart')->where($_POST)->setDec('goods_num');
			if($res){
                echo json_encode(['status'=>1,'msg'=>'添加成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'添加失败']);exit;
            }
		}
	}

    //购物车全选
    public function allElection(){
        $postData = I('post.');
        if($postData['status'] == 1){
            $res = M('cart')->where(['user_id'=>$this->user_id])->save(['selected'=>1]);
            if($res){
                echo json_encode(['status'=>1,'msg'=>'成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'失败']);exit;
            }
        }
    }

	//购物车选中
	public function pitchOn(){
		$postData = I('post.');
		$save['selected'] = $postData['status'];
		$where['user_id'] = $this->user_id;
		$where['goods_id'] = $postData['goods_id'];
		$res = M('cart')->where($where)->save($save);
		if($res){
            echo json_encode(['status'=>1,'msg'=>'成功']);exit;
        }else{
            echo json_encode(['status'=>0,'msg'=>'失败']);exit;
        }
	}

	//删除购物车商品
	public function delCart(){
		$_POST['user_id'] = $this->user_id;
		$res = M('cart')->where($_POST)->delete();
		if($res){
			$info = $this->getCartgoods($_POST['user_id']);
	        $this->assign('total', $info['total']);//购物车总价格和数量
	        $this->assign('cartList', $info['cartList']);//商品列表
            echo json_encode(['status'=>1,'msg'=>'删除成功','info'=>$this->fetch('cart/ajaxCartgoods')]);exit;
        }else{
            echo json_encode(['status'=>0,'msg'=>'删除失败']);exit;
        }
	}

	/**
     * ajax 将商品加入购物车
     */
    public function ajaxAddCart(){
        $goods_id = I("goods_id/d"); // 商品id
        $goods_num = I("goods_num/d");// 商品数量
        $item_id = I("item_id/d"); // 商品规格id
        if(empty($goods_id)){
            echo json_encode(['status'=>-1,'msg'=>'请选择要购买的商品','result'=>'']);exit;
        }
        if(empty($goods_num)){
            echo json_encode(['status'=>-1,'msg'=>'购买商品数量不能为0','result'=>'']);exit;
        }
        $cartLogic = new CartLogic();
        $cartLogic->setUserId($this->user_id);
        $cartLogic->setGoodsModel($goods_id);
        $cartLogic->setSpecGoodsPriceById($item_id);
        $cartLogic->setGoodsBuyNum($goods_num);
        try {
            $cartLogic->addGoodsToCart();
            echo json_encode(['status'=>1,'msg'=>'加入购物车成功']);exit;
        } catch (TpshopException $t) {
            $error = $t->getErrorArr();
            echo json_encode($error);exit;
        }
    }

    //确认订单页
    public function firmOrder(){
        if(empty($this->user_id)){
            $url = $_SERVER['HTTP_REFERER'].trim($_SERVER["REQUEST_URI"],'/');
            $this->redirect('cosmetology/User/login.html?url='.$_SERVER["REQUEST_URI"]);
        }
    	$goods_id = input("goods_id/d"); // 商品id
        $goods_num = input("goods_num/d");// 商品数量
        $item_id = input("item_id/d"); // 商品规格id
        $action = input("action/s"); // 行为
        $cartLogic = new CartLogic();
        $couponLogic = new CouponLogic();
        $cartLogic->setUserId($this->user_id);
        //立即购买
        if($action == 'buy_now'){
            $cartLogic->setGoodsModel($goods_id);
            $cartLogic->setSpecGoodsPriceById($item_id);
            $cartLogic->setGoodsBuyNum($goods_num);
            $buyGoods = [];
            try{
                $buyGoods = $cartLogic->buyNow();
            }catch (TpshopException $t){
                $error = $t->getErrorArr();
                $this->error($error['msg']);
            }
            $spec_goods_price = M('spec_goods_price');
            $buyGoods['item_id'] = $spec_goods_price->where(['goods_id'=>$buyGoods['goods_id'],'key'=>$buyGoods['spec_key']])->getField('item_id'); // 商品规格id
            $cartList['cartList'][0] = $buyGoods;
            $cartGoodsTotalNum = $goods_num;
            $taxation_form = I('type');
            if(empty($taxation_form)){ $taxation_form = null; }
            $this->assign('taxation_form', I('uid'));
            $this->assign('actions', $action);
            
        }else{
            if ($cartLogic->getUserCartOrderCount() == 0){
                // $this->error('你的购物车没有选中商品', 'Cart/cartList');
                $this->redirect('/cosmetology/Cart/cartList.html');exit;
            }
            $cartList['cartList'] = $cartLogic->getCartList(1); // 获取用户选中的购物车商品
            $cartGoodsTotalNum = count($cartList['cartList']);
        }
        $cartPriceInfo = $cartLogic->getCartPriceInfo($cartList['cartList']);  //初始化数据。商品总额/节约金额/商品总共数量
        $original_price = 0;
        foreach ($cartList['cartList'] as $k => $v) {
            $original_price += $v['goods_price'];
        }
        $cartList = array_merge($cartList,$cartPriceInfo);

        cookie("firm_order_url", setEnCode($_SERVER["REQUEST_URI"],'key'), 72*3600);
        
        $this->getAddress();
        $this->assign('original_price', $original_price);
        $this->assign('cartGoodsTotalNum', $cartGoodsTotalNum);
        $this->assign('cartList', $cartList['cartList']); // 购物车的商品
        $this->assign('cartPriceInfo', $cartPriceInfo);//商品优惠总价
        $this->assign('order_advertising_news', $this->getConfig('order_advertising_news'));//商品优惠总价
        // $this->assign('url', lock_url($_SERVER["REQUEST_URI"]));
        return $this->fetch();
    }


    //地址
    public function getAddress(){
        $user_address = Db::name('UserAddress');
        $address_list = $user_address->where(['user_id' => $this->user_id])->order('is_default desc')->select();
        if ($address_list) {
            $area_id = array();
            foreach ($address_list as $val) {
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
        $address_where['is_default'] = 1;
        $c = Db::name('UserAddress')->where(['user_id' => $this->user_id, 'is_default' => 1])->count(); // 看看有没默认收货地址
        if ((count($address_list) > 0) && ($c == 0)){ // 如果没有设置默认收货地址, 则第一条设置为默认收货地址
            $address_list[0]['is_default'] = 1;
            $user_address->where(['user_id'=>$this->user_id,'address_id'=>$address_list[0]['address_id']])->save(['is_default'=>1]);
        }
        $this->assign('address', $address_list[0]);
    }

    





}