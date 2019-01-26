<?php
namespace app\cosmetology\controller;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
class Pay extends Base{
	//微信h5支付
	public function wxh5pay(){
        $order_cn = I('get.order');
        $order_info = $this->get_order_info($order_cn);
        if(empty($order_info) || $order_info['pay_status'] == 1){//订单不存在或已支付
            $this->redirect('/cosmetology/Index/index.html');exit;
        }
        if($order_info['total_amount'] == 0 || $order_info['total_amount'] < 0){
            $this->redirect('/cosmetology/Order/placeOrder.html?order_id='.$order_info['order_id']);exit;
        }
        Vendor('wxh5pay.Base');
        $c = new \Base;
        if (strpos($_SERVER['HTTP_USER_AGENT'],'MicroMessenger') !== false ){
            $prepay_id = $c->getPrepayId($order_cn,'',$order_info['total_amount']);
            $json = $c->getJsParams($prepay_id);
            $this->assign('json', $json);
            $this->assign('order_cn', $order_cn);
            return $this->fetch();
        }else{
            $arr =  $c->unifiedOrder($order_cn,'h5',$order_info['total_amount']);
            header("location:" . $arr['mweb_url'] . '&redirect_url=' . urlencode('http://cellshop.umark.cc/cosmetology/Pay/red' . '?oid=' .$order_cn));
        }
	}

	//回调参数
	public function notify_url(){
		Vendor('wxh5pay.Base');
        $c = new \Base;
        if (strpos($_SERVER['HTTP_USER_AGENT'],'MicroMessenger') !== false ){
            $xml = file_get_contents("php://input");
            $log = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
            $id = $log['out_trade_no'];  //获取单号
            $order_info = $this->get_order_info($id);
            if(empty($order_info)){//订单不存在
                $this->redirect('/cosmetology/Index/index.html');exit;
            }
            if($order_info['pay_status'] == 1){//订单已支付
                $this->redirect('/cosmetology/Order/details.html?order_id='.$order_info['order_id']);exit;
            }
            $res = $this->modify_order($order_info,$oid,'微信公众号支付',$order_info['total_amount']);
            if($res !== false){
                $this->distribution($oid,$order_info);
                $this->redirect('/cosmetology/Order/details.html?order_id='.$order_info['order_id']);exit;
            }else{
                $this->redirect('/cosmetology/Index/index.html');exit;
            }
             $returnParams = [
                'return_code' => 'SUCCESS',
                'return_msg'  => 'OK'
            ];
            echo $c->ArrToXml($returnParams);
            exit('SUCCESS');  //打死不能去掉
        }else{
            $xmlData = $c->getPost();
            if($c->chekSign($arr)){//验证签名
                if($arr['return_code'] == 'SUCCESS' && $arr['result_code'] == 'SUCCESS'){
                    $returnParams = [
                        'return_code' => 'SUCCESS',
                        'return_msg'  => 'OK'
                    ];
                    echo $c->ArrToXml($returnParams);
                }
            }
        }
    }

    public function red(){
    	$oid = I('get.oid');
    	$order_info = $this->get_order_info($oid);

    	if(empty($order_info)){//订单不存在
			$this->redirect('/cosmetology/Index/index.html');exit;
		}
		if($order_info['pay_status'] == 1){//订单已支付
			$this->redirect('/cosmetology/Order/details.html?order_id='.$order_info['order_id']);exit;
		}
    	Vendor('wxh5pay.Base');
        $c = new \Base;
    	$arr = $c->sOrder($oid);
    	if($arr['trade_state'] == 'SUCCESS'){
			$res = $this->modify_order($order_info,$oid,'微信h5支付',$order_info['total_amount']);
    		if($res !== false){
    			$this->distribution($oid,$order_info);
    			$this->redirect('/cosmetology/Order/details.html?order_id='.$order_info['order_id']);exit;
    		}else{
    			$this->redirect('/cosmetology/Index/index.html');exit;
    		}
    	}
    }

    public function alipay_pay(){
    	$order = I('get.order');
    	$order_info = $this->get_order_info($order);
		if(empty($order_info) || $order_info['pay_status'] == 1){//订单不存在或已支付
			$this->redirect('/cosmetology/Index/index.html');exit;
		}

		if($order_info['total_amount'] == 0 || $order_info['total_amount'] < 0){
			$this->redirect('/cosmetology/Order/placeOrder.html?order_id='.$order_info['order_id']);exit;
		}
    	Vendor('Alipay.Alipay');
        $c = new \Alipay;
        $arr = $c->arr_val();
        //公共参数
        $pub_params = [
            'app_id'    => $arr['APPID'],
            'method'    =>  'alipay.trade.wap.pay', //接口名称 应填写固定值alipay.trade.page.pay
            'format'    =>  'JSON', //目前仅支持JSON
            'return_url'    => $arr['REURL'], //同步返回地址
            'charset'    =>  'UTF-8',
            'sign_type'    =>  'RSA2',//签名方式
            'sign'    =>  '', //签名
            'timestamp'    => date('Y-m-d H:i:s'), //发送时间
            'version'    =>  '1.0', //固定为1.0
            'notify_url'    => $arr['NOURL'], //异步通知地址
            'biz_content'    =>  '', //业务请求参数的集合
        ];
        
        //业务参数
        $api_params = [
            'out_trade_no'  => $order,//商户订单号
            'product_code'  => 'QUICK_WAP_WAY', //销售产品码 固定值
            'total_amount'  => $order_info['total_amount'], //总价 单位为元
            'subject'  => '购买商品', //订单标题
        ];
        $pub_params['biz_content'] = json_encode($api_params,JSON_UNESCAPED_UNICODE);
        
        $pub_params =  $c->setRsa2Sign($pub_params);
        $url = $arr['NEW_PAYGATEWAY'] . '?' . $c->getUrl($pub_params);
        header("location:" . $url);
    }

    //回调地址
    public function alipay(){
        Vendor('Wechat.Alipay');
        $c = new \Alipay;
        $arr = $c->arr_val();
        // 1.获取数据
        $postData = $_POST;
        
        if($postData['sign_type'] == 'RSA'){
            if(!$c->rsaCheck($c->getStr($postData), $arr['ALIPUBKEY'], $postData['sign']) ){
                $c->logs('log.txt', 'RSA签名失败!');
                exit();
            }else{
                $c->logs('log.txt', 'RSA签名成功!');
            }
        }elseif($postData['sign_type'] == 'RSA2'){
            if(!$c->rsaCheck($c->getStr($postData), $arr['NEW_ALIPUBKE'], $postData['sign'],'RSA2') ){
                $c->logs('log.txt', 'RSA2签名失败!');
                exit();
            }else{
                $c->logs('log.txt', 'RSA2签名成功!');
            }
        }else{
            exit('签名方式有误');
        }
        //验证是否来自支付宝的请求
        if(!$c->isAlipay($postData)){
            $c->logs('log.txt', '不是来之支付宝的通知!');
            exit();
        }else{
            $c->logs('log.txt', '是来之支付宝的通知验证通过!');
        }
        // 4.验证交易状态
        if(!$c->checkOrderStatus($postData)){
            $c->logs('log.txt', '交易未完成!');
            exit();
        }else{
           
            $c->logs('log.txt', '交易成功!');
        }
        //获取支付发送过来的订单号  在商户订单表中查询对应的金额 然后和支付宝发送过来的做对比
         $c->logs('log.txt', '订单号:' . $postData['out_trade_no'] . '订单金额:' . $postData['total_amount']);
        //更改订单状态
        echo 'success';
    }

    public function mine_pay(){
    	$order = I('get.out_trade_no');
    	$order_info = $this->get_order_info($order);
    	if(empty($order_info)){//订单不存在
			$this->redirect('/cosmetology/Index/index.html');exit;
		}
		if($order_info['pay_status'] == 1){//订单已支付
			$this->redirect('/cosmetology/Order/details.html?order_id='.$order_info['order_id']);exit;
		}
		$res = $this->modify_order($order_info,$order,'支付宝支付',$order_info['total_amount']);
		if($res !== false){
			$this->distribution($order,$order_info);
			$this->redirect('/cosmetology/Order/details.html?order_id='.$order_info['order_id']);exit;
		}else{
			$this->redirect('/cosmetology/Index/index.html');exit;
		}
    }
    
	//获取订单总金额
	public function get_order_info($order_cn){
		return M('order')->where(['order_sn'=>$order_cn,'user_id'=>$this->user_id])->find();
	}


	

}