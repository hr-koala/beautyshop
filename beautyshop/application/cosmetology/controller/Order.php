<?php
namespace app\cosmetology\controller;
use app\common\logic\CartLogic;
use app\common\logic\Pay;
use app\common\logic\PlaceOrder;
use app\common\logic\UsersLogic;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
use app\common\model\Order as OrderModel;
class Order extends Base{
	//提交并生成订单
    public function placeOrder(){
        if(empty($this->user_id)){
            $url = $_SERVER['HTTP_REFERER'].trim($_SERVER["REQUEST_URI"],'/');
            $this->redirect('/cosmetology/User/login.html?url='.urlencode($_SERVER["REQUEST_URI"]));
        }

        $order = M('order');
        $order_id = input("order_id/d", 0); //  收货地址id
        if(!empty($order_id)){
            $is_order = $order->where(['order_id'=>$order_id])->find();
            if(!empty($is_order)){
                $total_amount['total_amount'] = $is_order['total_amount'];
                $this->assign('order',$is_order['order_sn']);
                $this->assign('info',$total_amount);
                return $this->fetch();exit;
            }else{
                $this->redirect('/cosmetology/Index/Index.html');
            }
        }

        $address_id = input("address_id/d", 0); //  收货地址id
        $user_note = input("user_note/s", ''); // 用户留言
        $data['g.goods_id'] = $goods_id = input("goods_id/d"); // 商品id
        $data['g.goods_num'] = $goods_num = input("goods_num/d");// 商品数量
        $item_id = input("item_id/d"); // 商品规格key
        $action = input("actions"); // 行为
        $address = Db::name('user_address')->where("address_id", $address_id)->find();

        if($action == 'buy_now'){
            $data['o.user_id'] = $this->user_id;
            $data['o.order_status'] = $data['o.shipping_status'] = $data['o.pay_status'] = 0;
            $data['o.consignee'] = $address['consignee'];
            $data['o.province'] = $address['province'];
            $data['o.city'] = $address['city'];
            $data['o.district'] = $address['district'];
            $data['o.twon'] = $address['twon'];
            $data['o.address'] = $address['address'];
            $data['o.mobile'] = $address['mobile'];

            $spec_goods_price = M('spec_goods_price');
            $spec_info = $spec_goods_price->where(['item_id'=>$item_id])->find();
            $data['g.spec_key'] = $spec_info['key'];
            $data['g.spec_key_name'] = $spec_info['key_name'];

            $is_order = $order->alias('o')->join('newtp_order_goods g', 'g.order_id = o.order_id')->where($data)->find();
            if(!empty($is_order)){
                $total_amount['total_amount'] = $is_order['total_amount'];
                $this->assign('order',$is_order['order_sn']);
                $this->assign('info',$total_amount);
                return $this->fetch();exit;
            }
        }else{
            $cart = M('cart');
            $cart_info = $cart->where(['user_id'=>$this->user_id,'selected'=>1])->select();
            if(empty($cart_info)){
                $this->redirect('/cosmetology/Cart/cartList.html');exit;
            }
        }
        
        $cartLogic = new CartLogic();
        $pay = new Pay();
        try {
            $cartLogic->setUserId($this->user_id);
            $pay->setUserId($this->user_id);
            if($action == 'buy_now'){
                $cartLogic->setGoodsModel($goods_id);
                $cartLogic->setSpecGoodsPriceById($item_id);
                $cartLogic->setGoodsBuyNum($goods_num);
                $buyGoods = $cartLogic->buyNow();
                $cartList[0] = $buyGoods;
                $pay->payGoodsList($cartList);
            }else{
                $userCartList = $cartLogic->getCartList(1);
                $cartLogic->checkStockCartList($userCartList);
                $pay->payCart($userCartList);
            }
            $pay->delivery($address['district']);
            $pay->orderPromotion();
            // 提交订单
            $placeOrder = new PlaceOrder($pay);
            $placeOrder->setUserAddress($address);
            $placeOrder->setUserNote($user_note);
            $placeOrder->addNormalOrder();
            if($action != 'buy_now'){
                $cartLogic->clear();
            }
            $order = $placeOrder->getOrder();

            $taxation_form = I('taxation_form');
            if(!empty($taxation_form)){
                //报单
                $this->taxation_form($taxation_form,$order,$pay->toArray());
            }else{
                $this->assign('order',$order['order_sn']);
                $this->assign('info',$pay->toArray());
                return $this->fetch();
            }
        }catch(TpshopException $t){
            $error = $t->getErrorArr();
            echo $error;
        }
    }

    //报单
    public function taxation_form($uid,$order,$info){
        $total_amount = $info['total_amount']*2;
        $user_info = $this->user_info;
        if($user_info['bank_note'] < $total_amount){
            $this->redirect('/cosmetology/Agent/taxationForm.html');exit;
        }

        //订单
        $order_table = M('order');
        $save = ['uid'=>$uid,'goods_price'=>$order['goods_price']*2,'order_amount'=>$order['order_amount']*2,'total_amount'=>$order['total_amount']*2];
        $order_table->where(['order_sn'=>$order['order_sn']])->save($save);

        //商品
        $order_goods = M('order_goods');
        $order_goods_list = $order_goods->where(['order_id'=>$order['order_id']])->select();
        foreach ($order_goods_list as $k => $v) {
            $order_goods_save = ['final_price'=>$v['final_price']*2,'member_goods_price'=>$v['member_goods_price']*2];
            $order_goods->where(['rec_id'=>$v['rec_id']])->save($order_goods_save);
        }

        //报单用户
        $users = M('users');
        $user_save['bank_note'] = $user_info['bank_note'] - $total_amount;
        $user_save['bank_note_pay'] = $user_info['bank_note_pay'] + $total_amount;
        $user_save['bank_note_num'] = $user_info['bank_note_num'] + 1;
        $users->where(['user_id'=>$this->user_id])->save($user_save);

        $log_data = [
            'user_id' => $this->user_id,
            'change_time' => time(),
            'desc' => '报单使用保单币',
            'order_sn' => $order['order_sn'],
            'order_id' => $order['order_id'],
            'bank_note' => $total_amount,
            'type' => 8,
            'bank_note_type' => 1
        ];
        $account_log = M('account_log');
        $account_log->add($log_data);

        $users->where(['user_id'=>$uid])->save(['registration_type'=>2]);
        $this->modify_order($order,$order['order_sn'],'报单币抵扣',$total_amount,$uid);
        $this->distribution($order['order_sn'],$order,$uid);

        //被报单用户
        $uid_where = ['user_id'=>$uid,'pay_status'=>0,'uid'=>0];
        $uid_order_list = $order_table->where($uid_where)->field('order_id')->select();
        if(!empty($uid_order_list)){
            $uid_order_goods_list = $order_goods->where(['order_id'=>['in',implode(',', $uid_order_list['order_id'])],'is_agent'=>1])->field('order_id')->find();
            if(!empty($uid_order_goods_list)){
                $order_table->where(['order_id'=>$uid_order_goods_list['order_id']])->delete();
                $order_goods->where(['order_id'=>$uid_order_goods_list['order_id']])->delete();
            }
        }

        $this->redirect('/cosmetology/Agent/taxationFormSuccess.html');
    }

    //订单列表
    public function orderList(){
        $pitch_on = I('get.status');
        $order = M('order');
        $where = 'user_id = '.$this->user_id;
        $order_list = $this->getOrderlist($pitch_on);
        // dump($order_list);die;
        if(empty($pitch_on)){
            $pitch_on = 1;
        }
        $this->assign('pitch_on',$pitch_on);
        $this->assign('order_list',$order_list);
        return $this->fetch();
    }


    //获取订单信息
    public function getOrderlist($type=''){
        $where = ' user_id=:user_id';
        $bind['user_id'] = $this->user_id;
         //条件搜索
       if($type != ''){
           $where .= C(strtoupper($type));
       }
        // 搜索订单 根据商品名称 或者 订单编号     
        if($search_key){
          $where .= " and (order_sn like :search_key1 or order_id in (select order_id from `".C('database.prefix')."order_goods` where goods_name like :search_key2) ) ";
        }
        $where.=' and prom_type < 5 ';//虚拟拼团订单不列出来
        
        $order_str = "add_time DESC";
        $order_list = Db::name('order')->order($order_str)->where(['order_status'=>['neq',3]])->where($where)->bind($bind)->select();

        //获取订单商品
        $model = new UsersLogic();
        foreach($order_list as $k=>$v){
            // $v['invoice_no'] = $this->invoice_no($v['order_id']);
            $order_list[$k] = set_btn_order_status($v);  // 添加属性  包括按钮显示属性 和 订单状态显示属性
            $data = $model->get_order_goods($v['order_id']);
            $order_list[$k]['goods_list'] = $data['result'];
            
            foreach ($data['result'] as $key => $va) {
                $order_list[$k]['goods_count'] += $va['goods_num']; 
                $order_list[$k]['goods_list'][$key]['images'] = goods_thum_images($va['goods_id'],60,60);
            }
            if($order_list[$k]['prom_type'] == 4){
                $pre_sell_item =  Db::name('goods_activity')->where(array('act_id'=>$order_list[$k]['prom_id']))->find();
                $pre_sell_item = array_merge($pre_sell_item,unserialize($pre_sell_item['ext_info']));
                $order_list[$k]['pre_sell_is_finished'] = $pre_sell_item['is_finished'];
                $order_list[$k]['pre_sell_retainage_start'] = $pre_sell_item['retainage_start'];
                $order_list[$k]['pre_sell_retainage_end'] = $pre_sell_item['retainage_end'];
            }else{
                $order_list[$k]['pre_sell_is_finished'] = -1;//没有参与预售的订单
            }
            if ($v['order_status'] == 3 && ($v['pay_status'] == 1 || $v['pay_status'] == 4 || $v['pay_status'] == 3)) {
                $order_list[$k]['cancel_info'] = 1; // 取消订单详情
            }
        }
        return $order_list;
    }

    //查看物流
    public function viewLogistics(){
        if(IS_AJAX){
            $_POST['user_id'] = $this->user_id;
            $Order = new OrderModel();  
            $order_info = $Order::get($_POST);
            $order_info['total_num'] = 0;
            $order_info['goods_name'] = '';
            foreach ($order_info['order_goods'] as $k => $v) {
                $order_info['total_num']  += $v['goods_num'];
                $order_info['goods_name']  .= $v['goods_name'].'、';
            }
            //物流单号
            $order_info['invoice_no'] = $this->invoice_no($_POST['order_id']);
            //物流信息
            $logistics_info = $this->logistics_info($order_info['shipping_name'],$order_info['invoice_no']);
            // dump($logistics_info);die;
            $this->assign('order',$order_info);
            $this->assign('logistics_info',$logistics_info);
            echo json_encode(['status'=>1,'msg'=>'取消成功','info'=>$this->fetch('order/viewLogistics')]);exit;
        }
    }

    //物流信息
    public function logistics_info($name,$nums){
        if($name == '百世快递'){
            $name = '汇通快递';
            $names = $this->getValue($name,4);
            $url = "http://www.kuaidi100.com/query?type=".$names."&postid=".$nums;
            $response = json_decode(file_get_contents($url),TRUE);
            if($response['message'] == "ok"){
                return $response['data'];
            }else{
                return false;
            }
        }else{
            $name = $this->getValue($name,2);
            $url = "http://www.kuaidi100.com/query?type=".$name."&postid=".$nums;
            $response = json_decode(file_get_contents($url),TRUE);
            if($response['message'] == "ok"){
                return $response['data'];
            }else{
                $name = $this->getValue($name,4);
                $url = "http://www.kuaidi100.com/query?type=".$name."&postid=".$nums;
                $response = json_decode(file_get_contents($url),TRUE);
            }
            if($response['message'] == "ok"){
                return $response['data'];
            }else{
                return false;
            }
        }
    }

    public function getValue($firm,$num){
        $firms = mb_substr($firm,0,$num,'utf-8');
        Vendor('Wechat.GetPingYing');
        $c = new \Pinyin;
        $new = $c->getPinyin($firms);
        $str = preg_replace('/[ ]/', '', $new);//字符串去空格
        return $str;
    }

    //获取物流单号
    public function invoice_no($order_id){
        $delivery_record = M('delivery_doc')->where('order_id='.$order_id)->select();
        if($delivery_record){
            return $delivery_record[0]['invoice_no'];
        }else{
            return false;
        }
    }

    //取消订单
    public function cancelOrder(){
        if(IS_AJAX){
            $_POST['user_id'] = $this->user_id;
            $order = M('order');
            $inte = $order->where($_POST)->find();
            if(empty($inte)){
                echo json_encode(['status'=>0,'msg'=>'订单不存在']);exit;
            }
            if($inte['pay_status'] == 1){
                echo json_encode(['status'=>0,'msg'=>'此订单已经支付成功，无法取消']);exit;
            }
            $res = $order->where($_POST)->save(['order_status'=>3]);
            if($res){
                $data = [
                    'order_id' => $inte['order_id'],
                    'action_user' => 0,
                    'action_note' => '您取消了订单',
                    'order_status' => $inte['order_status'],
                    'pay_status' => $inte['pay_status'],
                    'log_time' => time(),
                    'status_desc' => '用户取消订单',
                    'shipping_status' => $inte['shipping_status'],
                ];
                Db::name('order_action')->add($data);//订单操作记录
                echo json_encode(['status'=>1,'msg'=>'取消成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'取消失败']);exit;
            }
        }
    }

    //确认收货
    public function confirm_receipt(){
        if(IS_AJAX){
            $_POST['user_id'] = $this->user_id;
            $order = M('order');
            $inte = $order->where($_POST)->find();
            if(empty($inte)){
                echo json_encode(['status'=>0,'msg'=>'订单不存在']);exit;
            }
            $res = $order->where($_POST)->save(['order_status'=>4,'confirm_time'=>time(),'shipping_status'=>1]);
            if($res){
                echo json_encode(['status'=>1,'msg'=>'收货成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'收货失败']);exit;
            }
        }
    }

    //订单详情
    public function details(){
        $order_id = intval(I('order_id'));//订单id
        $Order = new OrderModel();  
        $order = $Order::get(['order_id' => $order_id, 'user_id' => $this->user_id]);
        if (!$order) {
            $this->error('没有获取到订单信息');
        }
        // dump($order['order_status_detail']);dump($order);
        /*$info['consignee'] = $order['consignee']; //收件人姓名
        $info['mobile'] = $order['mobile'];  //收件人手机号
        $info['address'] = str_replace("，","",$order['full_address']);//地址
        $info['goods_price'] = $order['goods_price']; //商品总额
        $info['shipping_price'] = $order['shipping_price']; //邮费
        $info['total_amount'] = $order['total_amount'];  //订单总价
        $info['order_sn'] = $order['order_sn']; //订单编号
        $info['add_time'] = date('Y-m-d H:i:s',$order['add_time']); //时间*/

        /*
        $info['goods'][] = [];
        foreach ($order['order_goods'] as $k => $v) {
            $info['goods'][$k]['goods_name']  = $v['goods_name'];
            $info['goods'][$k]['goods_price'] = $v['goods_price'];
            $info['goods'][$k]['goods_num'] = $v['goods_num'];
            $info['goods'][$k]['images'] = goods_thum_images($v['goods_id'],60,60);
            $info['goods'][$k]['spec_key_name'] = $v['spec_key_name'];
        }*/
        $this->assign('order',$order);
        // $this->assign('info',$info);
        return $this->fetch();
    }


















}