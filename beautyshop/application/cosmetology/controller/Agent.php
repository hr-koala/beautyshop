<?php
namespace app\cosmetology\controller;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
class Agent extends Base {

	public function index(){
		//累计佣金
		$grand_total = $this->cumulative_commission(['neq',4]);
		$this->assign('grand_total',$grand_total);
		return $this->fetch();
	}

	//我的收入
	public function income(){
		$bonus['grand_total'] = $this->cumulative_commission(['not in','4,8']);//累计佣金
		$bonus['award'] = $this->get_award();
		//奖励列表
		
		$start_time = strtotime(date('Y-m-01', strtotime(date("Y-m-d"))));
		$where['change_time']  = array('between',$start_time.','.time());
		$bonus_list = $this->get_account_log($where);
		// dump($bonus_list);
		$this->bonus_notes();
		$this->assign('bonus',$bonus);
		$this->assign('bonus_list',$bonus_list);
		return $this->fetch();
	}

	public function ajaxIncome(){
		if(IS_AJAX){
			$getthemonth = $this->getthemonth(I('post.date'));
			$where['change_time']  = array('between',strtotime($getthemonth['0']).','.strtotime($getthemonth['1']));
			$bonus_list = $this->get_account_log($where);
			$this->assign('bonus_list',$bonus_list);
			echo json_encode(['status'=>1,'msg'=>'成功','info'=>$this->fetch('agent/ajaxIncome')]);exit;
		}
	}

	//获得奖金记录
	public function get_account_log($where){
		$account_log = M('account_log');
		$where['type'] = ['not in','4,8'];
		$where['user_id'] = $this->user_id;
		$bonus_list = $account_log->where($where)->order('change_time DESC')->select();
		return $bonus_list;
	}

	//获取某个月的第一天与最后一天
	public function getthemonth($date){
	   	$firstday = date('Y-m-01', strtotime($date));
	   	$lastday = date('Y-m-d', strtotime("$firstday +1 month -1 day"));
	   	return array($firstday, $lastday);
	} 

	//当前用户获得奖金总额
	public function get_award(){
		return $award = [
			['name'=>'直推奖','value'=>$this->cumulative_commission(1)],//直推
			['name'=>'推广奖','value'=>$this->cumulative_commission(2)],//推广
			['name'=>'销售提成','value'=>$this->cumulative_commission(6)],//销售提成
			['name'=>'晋级奖','value'=>$this->cumulative_commission(3)],//晋级奖
			['name'=>'同级奖','value'=>$this->cumulative_commission(5)],//同级奖
			['name'=>'积分红利','value'=>$this->cumulative_commission(7)],//积分红利
		];
	}

	//累计佣金
	public function cumulative_commission($type){
		return $grand_total = M('account_log')->where(['user_id'=>$this->user_id,'type'=>$type])->sum('user_money');
	}

	//奖励说明
	public function bonus_notes(){
		//奖励说明
		$bonus_notes = $this->getConfig('bonus_notes');
		$notes_list['bonus_notes'] = explode('|', $bonus_notes);
		//销售业绩比例表
		$z_sales_award = M('z_sales_award');
		$notes_list['sales_award'] = $z_sales_award->where('id>0')->select();
		$this->assign('notes_list',$notes_list);
	}

	//提现记录
	public function drawingRecord(){
		$withdrawals = M('withdrawals');
		//提现总金额
		$info['total_withdrawals'] = $withdrawals->where(['user_id'=>$this->user_id,'status'=>2])->sum('money');
		//提现记录
		$info['drawing_record'] = $withdrawals->where(['user_id'=>$this->user_id])->order('create_time DESC')->select();
		$this->assign('info',$info);
		$this->assign('url',$this->is_bank_number());
		return $this->fetch();
	}

	//分销佣金（可提现金额）
	public function withdrawMoney(){
		$this->assign('url',$this->is_bank_number());
		return $this->fetch();
	}

	//是否绑定银行卡号
	public function is_bank_number(){
		$user_info = $this->user_info;
		if(!empty($user_info['opening_bank']) && !empty($user_info['bank_account']) && !empty($user_info['bank_name'])){
			$url = U('cosmetology/Agent/drawing');//去提现
		}else{
			$url = U('cosmetology/Agent/bindingBankNumber');//绑定银行卡号
		}
		return $url;
	}

	//去提现
	public function drawing(){
		if(IS_AJAX){
			$user_info = $this->user_info;
			$money = I('post.money');
			if($money > $user_info['user_money'] || $user_info['user_money'] == 0){
				echo json_encode(['status'=>0,'msg'=>'余额不足']);exit;
			}
			$user_money = $user_info['user_money'] - $money;
			if($user_money < 0){
				echo json_encode(['status'=>0,'msg'=>'余额不足']);exit;
			}
			$users = M('users');
			$res = $users->where(['user_id'=>$user_info['user_id']])->save(['user_money'=>$user_money]);
			$data = [
				'user_id' => $user_info['user_id'],
				'money' => $money,
				'create_time' => time(),
				'bank_name' => $user_info['opening_bank'],
				'bank_card' => $user_info['bank_account'],
				'realname' => $user_info['bank_name'],
				'remark' => '提现申请'
			];
			$withdrawals = M('withdrawals');
			$withdrawals->add($data);
			if($res){
				echo json_encode(['status'=>1,'msg'=>'申请成功','info'=>$user_money]);exit;
			}else{
				echo json_encode(['status'=>0,'msg'=>'申请失败']);exit;
			}
		}else{
			return $this->fetch();
		}
	}

	//绑定银行卡号
	public function bindingBankNumber(){
		if(IS_AJAX){
			$res = M('users')->where(['user_id'=>$this->user_id])->save(I('post.'));
			if($res){
				echo json_encode(['status'=>1,'msg'=>'绑定成功']);exit;
			}else{
				echo json_encode(['status'=>0,'msg'=>'绑定失败']);exit;
			}
		}else{
			return $this->fetch();
		}
	}

	//邀请好友
	public function inviteFriends(){
		//邀请记录
		$subordinate = $this->get_all_subordinate();
		
		$this->get_all_level();//获得所有层级
		$this->bonus_notes();//奖励说明
		$this->assign('invitation_record',$subordinate['invitation_record']);
		return $this->fetch();
	}

	//获得所有层级
	public function get_all_level(){
		$all_level = M('user_level')->where('level_id >0')->select();
		$this->assign('all_level',$all_level);
		return $all_level;
	}

	//生成邀请卡页面
	public function generatingCard(){
		$user_info = $this->user_info;
		if(!empty($user_info['referral_code']) && $user_info['referral_code'] !== ''){
			$referral_code = $user_info['referral_code'];
		}else{
		    $url = 'http://'.$_SERVER["SERVER_NAME"].'/cosmetology/User/register?code='.$user_info['invitation_code_own'];
			vendor("phpqrcode");
			$QRcode = new \QRcode();
			$path = "./public/upload/qr_code/";
	       	if(!file_exists($path)){   
	           	mkdir($path, 0700,true);
	       	}
	       	$time = $where['user_id'].time().'.png';//生成的二维码文件名
	       	$fileName = $path.$time;//1.拼装生成的二维码文件路径
		    $data =$url;
	 		$level = 'L';  //3.纠错级别：L、M、Q、H  
	       	$size = 10;//4.点的大小：1到10,用于手机端4就可以了 
	       	ob_end_clean();//清空缓冲区
	       	$QRcode->png($data,$fileName,$level,$size,2);
	       	$fileName_new = trim($fileName,'.');
	       	M('users')->where(['user_id'=>$user_info['user_id']])->save(['referral_code'=>$fileName_new]);
	       	// $referral_code = 'https://'.$_SERVER['SERVER_NAME'].$fileName_new;
	       	$referral_code = $fileName_new;
		}
		$this->assign('referral_code',$referral_code);
		return $this->fetch();
	}

	//我的邀请(所有我的直属下线)
	public function myInvitation(){
		$subordinate = $this->get_all_subordinate();
		$this->assign('subordinate',$subordinate);
		$this->get_all_level();//获得所有层级
		return $this->fetch();
	}

	//我的下级
	public function get_all_subordinate($level = ''){
		$user_info = $this->user_info;
		$users = M('users');
		if($level === 1){//直属代理身份下级
			$subordinate['invitation_record'] = $users->alias('u')->join('newtp_user_level l', 'l.level_id = u.level')->where(['u.invitation_code_others'=>$user_info['invitation_code_own'],'u.is_lock'=>0])->order('reg_time desc')->getField('u.user_id,u.reg_time,u.mobile,l.level_name');
		}elseif($level === ''){//所有下级
			$subordinate['invitation_record'] = $users->where(['invitation_code_others'=>$user_info['invitation_code_own'],'is_lock'=>0])->order('reg_time desc')->getField('user_id,reg_time,mobile,level');
		}
		$subordinate['count'] = count($subordinate['invitation_record']);
		return $subordinate;
	}

	//我的直推(我的直属下线成为代理的用户)
	public function myRecommend(){
		$subordinate = $this->get_all_subordinate(1);
		$this->assign('subordinate',$subordinate);
		return $this->fetch();
	}

	//我的团队
	public function myTeam(){
		$user_info = $this->user_info;
		//无限下级
		$users = M('users');
		$z_team = M('z_team');
		$arr = $users->where(['user_id'=>['neq',$user_info['user_id']]])->field('user_id,first_leader,reg_time,mobile,level')->select();
		$inte = $this->node_merge($arr,$user_info['user_id']);
		if($inte !== ''){
			$new_inte = explode(',', trim($inte));
			$team = [];
			foreach ($arr as $k => $v) {
				foreach ($new_inte as $val) {
					if($v['user_id'] == $val){
						$team[] = $v;
					}
				}
			}
			$count_team = count($team);
			if($count_team != $user_info['gvxx']){
				$users->where(['user_id'=>$user_info['user_id']])->save(['gvxx'=>$count_team]);
			}
			$this->get_all_level();//获得所有层级
			$this->assign('team',$team);
		}else{
			$count_team = 0;
		}
		$this->assign('count_team',$count_team);
		return $this->fetch();
	}

	public function node_merge($arr,$pid,$i=1){
		$new_arr = '';
	    foreach($arr as $k => $v) {
	        if ($v['first_leader']==$pid) {
	            $child=$this->node_merge($arr,$v['user_id'],$i+1);
	            $new_arr .= $v['user_id'].',';
	            $new_arr .= $child;
	            /*$v['child']=$this->node_merge($arr,$v['user_id']);
	            $new_arr[]=$v;*/
	        }
	    }
    	return $new_arr;
	}

	//等级说明
	public function levelDescription(){
		$all_level = $this->get_all_level();//等级介绍
		$user_info = $this->user_info;
		$team_num = $agent_num = '';
		foreach ($all_level as $k => $v) {
			if($v['level_id'] == 2 && $user_info['level'] == 2){//团队人数与代理
				$lack['upgrade'] = $agent_num = $team_num = $k;
			}elseif($v['level_id'] == $user_info['level'] && $user_info['level'] != 14){
				$lack['upgrade'] = $agent_num = $k;
			}
		}
		$team = $all_level[$team_num+1]['team'];
		if($user_info['gvxx'] < $team && $team_num !== ''){
			$lack['team'] = $team - $user_info['gvxx'];
		}
		$agent= $all_level[$agent_num+1]['lower_rank'];
		if($user_info['lower_stratum_number'] < $agent && $agent_num !== ''){
			$lack['agent'] = $agent - $user_info['lower_stratum_number'];
		}
		$this->display_level($user_info['level']);
		$this->assign('lack',$lack);
		return $this->fetch();
	}

	public function display_level($level_id){
		switch ($level_id) {
			case '10':
				$display = 0;
				break;
			case '11':
				$display = 1;
				break;
			case '12':
				$display = 2;
				break;
			case '13':
				$display = 3;
				break;
			case '14':
				$display = 4;
				break;
			default:
				$display = 5;
				break;
		}
		$this->assign('display',$display);
	}

	//报单资料 
	public function reprotInfor(){
		return $this->fetch();
	}

	//提交报单资料成功
	public function reprotInforSuccess(){
		return $this->fetch();
	}

	//报单币
	public function bankNote(){
		$account_log = M('account_log');
		$type = I('get.type');
		if(!empty($type)){
			$where['bank_note_type']=1;
			$this->assign('type',$type);
		}
		$where['type'] = 8;
		$where['user_id'] = $this->user_id;
		$list = $account_log->where($where)->select();
		$this->assign('list',$list);
		return $this->fetch();
	}

	//报单管理
	public function reportManagement(){
		$users = M('users');
		$user_info = $this->user_info;
		//审核通过人数
		$adopt_num = $users->where(['invitation_code_others'=>$user_info['invitation_code_own'],'registration_type'=>2])->count();
		//用户
		$type = I('get.type');
		if(empty($type) || $type == ''){
			$type = 'all';
		}
		if($type == 'auditing'){
			$where['registration_type'] = 1;
		}elseif($type == 'adopt'){
			$where['registration_type'] = 2;
		}elseif($type == 'all'){
			$where['registration_type'] = ['in','1,2'];
		}
		$where['invitation_code_others'] = $user_info['invitation_code_own'];
		$user_list = $users->where($where)->select();
		$this->assign('adopt_num',$adopt_num);
		$this->assign('user_list',$user_list);
		$this->assign('type',$type);
		return $this->fetch();
	}

	//报单
	public function taxationForm(){
		$user_id = I('get.user_id');
		if(empty($user_id)){
			echo "<script> window.history.go(-1); </script>";exit;
		}
		$where = ['is_on_sale'=>1 , 'is_agent'=>1 , 'original_img'=>['neq',''] , 'is_virtual' => ['exp' ,"=0 or virtual_indate > ".time()]];
		$field = 'goods_id,goods_name,shop_price,goods_remark,original_img,is_agent';
		$goods = Db::name('goods');
		$goods_list = $goods->where($where)->field($field)->order('on_time desc')->cache(true,TPSHOP_CACHE_TIME)->select();
		$goods_sum = $goods->where($where)->sum('shop_price');
		$this->assign('user_id',$user_id);
		$this->assign('goods_sum',$goods_sum);
		$this->assign('goods_list',$goods_list);
		return $this->fetch();
	}

	//报单成功
	public function taxationFormSuccess(){
		return $this->fetch();
	}





}