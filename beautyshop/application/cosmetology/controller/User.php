<?php
namespace app\cosmetology\controller;
use app\common\logic\UsersLogic;
use think\Controller;
use think\Db;
use think\Session;

class User extends Base {
	//注册
	public function register(){
        if(IS_AJAX){
            $postData = I('post.');

            if($postData['psw'] != $postData['againPsw']){
                $return_data['status'] = 0;
                $return_data['msg'] = '两次输入的密码不一致';
                echo json_encode($return_data);exit;
            }
            //验证验证码
            $z_phone_code = M('z_phone_code');
            $phone_code = $z_phone_code->where(['phone'=>$postData['userphone'],'type'=>1])->find();

            if(empty($phone_code) || $postData['yzcode'] != $phone_code['code']){
                $return_data['status'] = 0;
                $return_data['msg'] = '验证码错误';
                echo json_encode($return_data);exit;
            }
            $time = time();
            $send_time = $time - $phone_code['create_time'];
            if($send_time>601 || $phone_code['status'] == 1){
                $return_data['status'] = 0;
                $return_data['msg'] = '验证码已过期，请重新获取验证码';
                echo json_encode($return_data);exit;
            }
            //用户
            $users = M('users');
            $user_info = $users->where(['mobile'=>$postData['userphone']])->find();
            if(!empty($user_info)){
                $return_data['status'] = 0;
                $return_data['msg'] = '此手机号用户已存在，请直接登录账号';
                echo json_encode($return_data);exit;
            }

            $in_card_user = $users->where(['id_card'=>$postData['useridcard']])->find();
            if(!empty($in_card_user)){
                $return_data['status'] = 0;
                $return_data['msg'] = '此身份证用户已存在，请直接登录账号';
                echo json_encode($return_data);exit;
            }

            $data = [
                'password'            => encrypt($postData['psw']),
                'reg_time'            => $time,
                'mobile'              => $postData['userphone'],
                'mobile_validated'    => 1,
                'id_card'             => $postData['useridcard'],
                'registered_name'     => $postData['username'],
                // 'invitation_code_own' => getyqcodes(),//自己的邀请码
            ];

            if(!empty($postData['registration_type']) && $postData['registration_type'] == 1){
                $data['registration_type'] = 1;
            }

            if($postData['code'] != ''){
                $data['invitation_code_others'] = $postData['code'];
                
                $superior_info = $users->where(['invitation_code_own'=>$postData['code']])->find();
                if(empty($superior_info)){
                    $return_data['status'] = 0;
                    $return_data['msg'] = '邀请码填写错误';
                    echo json_encode($return_data);exit;
                }
                //判断上级是否有上级
                if($superior_info['invitation_code_others'] != '0'){
                    //上级的上级
                    $superior_info_two = $users->where(['invitation_code_own'=>$superior_info['invitation_code_others']])->find();
                }
                /*if(!empty($superior_info_two)){
                    $superior_data_two['gvxx'] = $superior_info_two['gvxx']+1;
                    $a = $users->where(['user_id'=>$superior_info_two['user_id']])->save($superior_data_two);//上级的上级团队加1
                    if($superior_info_two['invitation_code_others'] != '0'){
                        $this->gvxx($users,$superior_info_two['invitation_code_others']);
                    }
                }*/
                /*if($superior_info['underling_number'] != 0){//判断是否有下级
                    $data['extension_userid'] = $superior_info['user_id'];//可返佣推广奖用户
                }else{
                    if(!empty($superior_info_two) && $superior_info['extension_userid'] != $superior_info_two['user_id']){
                        $data['extension_userid'] = $superior_info_two['user_id'];//可返佣推广奖用户
                    }
                }*/
                $data['first_leader'] = $superior_info['user_id'];
                //上级的团队加1，直属下级加1
                $superior_data = [
                    'underling_number' => $superior_info['underling_number']+1,
                    // 'gvxx' => $superior_info['gvxx']+1,
                ];
                $users->where(['user_id'=>$superior_info['user_id']])->save($superior_data);
            }
            $res = $users->add($data);
            if($res){
                $z_phone_code->where(['phone'=>$postData['userphone'],'type'=>1])->save(['status'=>1]);
                $return_data['status'] = 1;
                $return_data['msg'] = '注册成功，请登录账号';
            }else{
                $return_data['status'] = 0;
                $return_data['msg'] = '注册失败';
            }
            echo json_encode($return_data);
        }else{
            //邀请码
            $code = I('get.code');
            $this->assign('code',$code);
            return $this->fetch();
        }
	}

    public function gvxx($users,$code_others){
        $superior = $users->where(['invitation_code_own'=>$code_others])->find();
        if(!empty($superior)){
            $data['gvxx'] = $superior['gvxx']+1;
            $users->where(['user_id'=>$superior['user_id']])->save($data);
            if($superior['invitation_code_others'] != '0'){
                $this->gvxx($users,$superior['invitation_code_others']);
            }
        }
    }

    //发送验证码
    public function sendcode(){
        if (IS_POST) {
            $z_phone_code = M('z_phone_code');
            $phone = I('post.phone');
            $type = intval(I('post.type'));
            if ($type != 1 && $type != 2 ) {//注册验证码,2//忘记密码验证码
                echo json_encode(array("state" => -1, "datas" => "参数错误"));exit;
            }
            $map = array();
            $map['type'] = $type;
            $map['phone'] = $phone;
            $info = $z_phone_code->where($map)->find();
            if (!empty($info)) {
                $now = time();
                $createtime = $info['create_time'];
                if (($now - $createtime) <= 180) {
                    echo json_encode(array("state" => -1, "datas" => "请不要频繁发送验证码！"));exit;
                }
            }
            $config = M('config')->where(['name'=>['in','sms_appkey,sms_secretKey,sms_product']])->select();
            $newRes = [];
            foreach ($config as $rs){
                $newRes[$rs['name']] = $rs['value'];
            }
            $code = rand(100000, 999999);
            $target = "http://sms.bamikeji.com:8890/mtPort/mt/normal/send?phonelist=" . $phone . "&content=您的验证码为：" . $code . "，为了保护您的账户安全，验证码请勿转发他人，有效时间10分钟。【".$newRes['sms_product']."】&passwd=".$newRes['sms_secretKey']."&uid=".$newRes['sms_appkey'];

            $result = file_get_contents($target);
            $res = json_decode($result, true);
            $time = time();
            if ($res['success']) {
                if (!empty($info)) {
                    $data['code'] = $code;
                    $data['status'] = 0;
                    $data['create_time'] = $time;
                    $z_phone_code->where($map)->save($data);
                } else {
                    $data['type'] = $type;
                    $data['code'] = $code;
                    $data['create_time'] = $time;
                    $data['phone'] = $phone;
                    $z_phone_code->add($data);
                }
                echo json_encode(array("state" => 1, "datas" => "验证码发送成功！"));exit;
            } else {
                echo json_encode(array("state" => 0, "datas" => $res['msg']));exit;
            }
        }
    }

	//登录
	public function login(){
        if(IS_AJAX){
            $postData = I('post.');
            $users = M('users');
            $user_info = $users->where(['mobile'=>$postData['userphone']])->find();
            if(empty($user_info)){
                echo json_encode(['status'=>0,'msg'=>'账号不存在！']);exit;
            }
            if($user_info['is_lock'] == 1){
                echo json_encode(['status'=>0,'msg'=>'账号异常已被锁定！']);exit;
            }
            if($user_info['password'] != encrypt($postData['psw'])){
                echo json_encode(['status'=>0,'msg'=>'登录密码错误']);exit;
            }
            $data = [
                'last_login' => time(),
                'last_ip' => $_SERVER["REMOTE_ADDR"],
            ];
            $users->where(['user_id'=>$user_info['user_id']])->save($data);
            cookie("mobile", setEnCode($user_info['mobile'],'key'), 72*3600);
            cookie("user_id", setEnCode($user_info['user_id'],'key'), 72*3600);
            cookie("level_id", setEnCode($user_info['level'],'key'), 72*3600);
            echo json_encode(['status'=>1,'msg'=>'登录成功']);exit;
        }else{
            $url = I('get.url');
            if(empty($url)){ $url = 0; }
            $this->assign('url',$url);
            return $this->fetch();
        }
	}

    //忘记密码
    public function forgot_psw(){
        if(IS_AJAX){
            $postData = I('post.');
            $users = M('users');
            $user_info = $users->where(['mobile'=>$postData['userphone']])->find();
            if(empty($user_info)){
                echo json_encode(['status'=>0,'msg'=>'账号不存在！']);exit;
            }
            if($user_info['is_lock'] == 1){
                echo json_encode(['status'=>0,'msg'=>'账号异常已被锁定！']);exit;
            }
            //验证验证码
            $z_phone_code = M('z_phone_code');
            $phone_code = $z_phone_code->where(['phone'=>$postData['userphone'],'type'=>2])->find();

            if(empty($phone_code) || $postData['yzcode'] != $phone_code['code']){
                echo json_encode(['status'=>0,'msg'=>'验证码错误']);exit;
            }
            $time = time();
            $send_time = $time - $phone_code['create_time'];
            if($send_time>601 || $phone_code['status'] == 1){
                echo json_encode(['status'=>0,'msg'=>'验证码已过期，请重新获取验证码']);exit;
            }
            $data['password'] = encrypt($postData['psw']);
            $res = $users->where(['user_id'=>$user_info['user_id']])->save($data);
            if($res){
                $z_phone_code->where(['phone'=>$postData['userphone'],'type'=>2])->save(['status'=>1]);
                $this->clearData();
                echo json_encode(['status'=>1,'msg'=>'修改成功，请登录账号']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'修改失败']);exit;
            }
        }else{
            return $this->fetch();
        }
    }

	//退出登录
	public function logout(){
        // session_unset();
        // session_destroy();
        $this->clearData();
        header("Location:" . U('cosmetology/User/login'));
        exit();
    }

    public function clearData(){
        cookie("mobile", null);
        cookie("user_id", null);
        cookie("level_id", null);
    }

    //添加地址
    public function newAddress(){
        header("Content-Type: text/html;charset=utf-8");
        $user_address = M('user_address');
        if(IS_POST){
            $dataPost = I('post.');
            $data = [
                'user_id'    => $this->user_id,
                'consignee'  => $dataPost['username'],
                'province'   => $dataPost['prov'],
                'city'       => $dataPost['city'],
                'district'   => $dataPost['area'],
                'address'    => $dataPost['address'],
                'mobile'     => $dataPost['usertel']
            ];

            if(empty($dataPost['is_default'])){
                $data['is_default'] = 0;
            }elseif(!empty($dataPost['is_default'])){
                $data['is_default'] = 1;
                $user_address->where(['user_id'=>$data['user_id'],'is_default'=>1])->save(['is_default'=>0]);
            }
            if(!empty($dataPost['address_id'])){
                $res = $user_address->where(['address_id'=>$dataPost['address_id']])->save($data);
            }else{
                $res = $user_address->add($data);
            }
            if(empty($dataPost['url'])){
                $url = '/cosmetology/Mine/address.html';

            }elseif(!empty($dataPost['url']) && $data['is_default'] == 1){
                // dump(cookie('firm_order_url'));die;
                $url = getDeCode($_COOKIE['firm_order_url'],'key');

            // dump(1111);dump($url);die;
                /*$url = unlock_url($dataPost['url']);
                echo "<script type='text/javascript' src='__STATIC__/tonghaoran/js/jquery.min.js'></script>";
                echo "<script>var url = '".$url."'; var reg = /(\.html\S)/g; url = url.replace(reg, '.html?'); window.location.href=url; </script>";exit;*/
            }elseif(!empty($dataPost['url']) && $data['is_default'] == 0){
                $url = '/cosmetology/Mine/address.html?url='.cookie('firm_order_url');
                /*$url = '/cosmetology/Mine/address.html?url='.$dataPost['url'];*/
            }
            // dump($url);die;
            $this->redirect($url);
            /*echo "<script> window.location.href='".$url."'; </script>";exit;*/
        }else{
            $province = DB::name('region')->where(['level'=>['eq',1]])->field('id,name,parent_id')->select();
            $city = DB::name('region')->where(['level'=>['eq',2]])->field('id,name,parent_id')->select();
            $district = DB::name('region')->where(['level'=>['eq',3]])->field('id,name,parent_id')->select();
            $inte = [];
            foreach ($city as $key => $val){
                $inte[] = $val;
                foreach ($district as $k => $v){
                    if($v['parent_id'] == $val['id']){
                        $inte[$key]['district'][] = $v;
                    }
                }   
            }
            $list = [];
            foreach ($province as $key => $val){
                $list['province'][$key] = $val;
                foreach ($inte as $k => $v){
                    if($v['parent_id'] == $val['id']){
                        $list['province'][$key]['city'][] = $v;
                    }
                }
            }
            $address_id = I('get.address_id');
            $this->getAddress($user_address,$address_id);
            $this->assign('list', json_encode($list));
            $this->assign('url', I('get.url'));
            return $this->fetch();
        }
    }

    public function getAddress($user_address,$address_id){
        $address = $user_address->where(['user_id' => $this->user_id,'address_id'=>$address_id])->find();
        if ($address) {
            $area_id = $address['province'].','.$address['city'].','.$address['district'].','.$address['twon'];
            $region = Db::name('region')->where("id", "in", $area_id)->getField('id,name');
        }
        $this->assign('region', $region);
        $this->assign('address', $address);
    }

    //修改手机号
    public function changeNumber(){
        if(IS_AJAX){
            $postData = I('post.');
            $users = M('users');
            $user_info = $users->where(['mobile'=>$postData['userphone']])->find();
            if(!empty($user_info)){
                echo json_encode(['status'=>0,'msg'=>'此账号已存在！']);exit;
            }
            if($user_info['is_lock'] == 1){
                echo json_encode(['status'=>0,'msg'=>'账号异常已被锁定！']);exit;
            }
            //验证验证码
            $z_phone_code = M('z_phone_code');
            $phone_code = $z_phone_code->where(['phone'=>$postData['userphone'],'type'=>3])->find();

            if(empty($phone_code) || $postData['yzcode'] != $phone_code['code']){
                echo json_encode(['status'=>0,'msg'=>'验证码错误']);exit;
            }
            $time = time();
            $send_time = $time - $phone_code['create_time'];
            if($send_time>601 || $phone_code['status'] == 1){
                echo json_encode(['status'=>0,'msg'=>'验证码已过期，请重新获取验证码']);exit;
            }
            $data['mobile'] = $postData['userphone'];
            $res = $users->where(['user_id'=>$this->user_id])->save($data);
            if($res){
                $z_phone_code->where(['phone'=>$postData['userphone'],'type'=>3])->save(['status'=>1]);
                cookie("mobile", setEnCode($postData['userphone'],'key'), 72*3600);
                echo json_encode(['status'=>1,'msg'=>'修改成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'修改失败']);exit;
            }
        }else{
            return $this->fetch();
        }
    }

    //修改手机号码成功
    public function changeNumberSuccess(){
        return $this->fetch();
    }


    
}