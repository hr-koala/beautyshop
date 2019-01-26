<?php
namespace app\cosmetology\controller;
use think\Controller;
use think\Db;
use think\Session;

class Base extends Controller {
	public $mobile;
    public $user_id;
    public $level_id;
    public $level;
    public $user_info;
    public $uid;
    public $ulevel;

	public function _initialize(){
		if(!empty($_COOKIE['mobile']) && !empty($_COOKIE['user_id']) && !empty($_COOKIE['level_id'])){
			$this->mobile   = getDeCode($_COOKIE['mobile'],'key');
			$this->user_id  = getDeCode($_COOKIE['user_id'],'key');
			$this->level_id = getDeCode($_COOKIE['level_id'],'key');
		}
		if(!in_array(CONTROLLER_NAME, ['Index','Goods','User'])){
			if(empty($_COOKIE['mobile']) && empty($_COOKIE['user_id']) && empty($_COOKIE['level_id'])){
				$this->redirect('/cosmetology/User/login.html?url='.$_SERVER["REQUEST_URI"]);
			}
		}
		$this->getLevel();
		$this->getFooter();
	}

	public function getLevel(){
		$users = M('users');
		$user_info = $users->where(['user_id'=>$this->user_id])->find();
		$this->user_info = $user_info;
		$this->assign('user_info',$user_info);

		$user_level = M('user_level');
		$level = $user_level->where(['level_id'=>$user_info['level']])->find();
		$level_status = 0;
		if(!empty($level)){
			$this->assign('level',$level);
			$level_status = 1;
			$this->level = $level;
		}
		$this->assign('level_status',$level_status);
	}

	public function getFooter(){
		switch (CONTROLLER_NAME) {
			case 'Index':
				$footer = 1;
				break;
			case 'Goods':
				$footer = 2;
				break;
			case 'Cart':
				$footer = 3;
				break;
			case 'Agent':
				$footer = 4;
				break;
			default:
				$footer = 1;
				break;
		}
		if(in_array(CONTROLLER_NAME, ['Mine','User','Order'])){
			$footer = 5;
		}
		$this->assign('footer',$footer);
	}

	//推荐专区
	public function recommend_area(){
	 	$where = ['is_on_sale'=>1 , 'is_recommend'=>1 , 'is_virtual' => ['exp' ,"=0 or virtual_indate > ".time()]];
	 	$recommend_area = $this->select_goods($where,'sort ASC');
        return $recommend_area;
	}

	//热卖专区
	public function hot_area(){
		$where = ['is_on_sale'=>1 , 'is_hot'=>1 , 'is_virtual' => ['exp' ,"=0 or virtual_indate > ".time()]];
	 	$hot_area = $this->select_goods($where,'sort ASC');
        return $hot_area;
	}

	//查询商品
	public function select_goods($where,$order){
		$where['original_img'] = ['neq',''];
		if(!empty($this->level)){
			$where['is_agent'] = ['neq',1];
		}
		$select_goods = Db::name('goods')->where($where)->field('goods_id,goods_name,shop_price,goods_remark,original_img,is_agent')->order($order)->cache(true,TPSHOP_CACHE_TIME)->select();
		return $select_goods;
	}

	//获取配置参数
	public function getConfig($name){
		return M('config')->where(['name'=>$name])->getField('value');
	}

    //订单已完成
    public function modify_order($order,$order_cn,$pay_name,$money,$uid=''){
    	if($this->uid === ''){
			$this->uid = $this->user_id;
		}else{
			$this->uid = $uid;
		}
        minus_stock($order);//下单减库存
        update_pay_status($order['order_sn']);
    	$order = M('order');
		$save['order_status'] = $save['pay_status'] = 1;
		$save['pay_name'] = $pay_name;
		$save['pay_time'] = time();
		$res = $order->where(['order_sn'=>$order_cn])->save($save);
        M('users')->where(['user_id'=>$this->uid])->setInc('total_amount',$money);
		if(!$res){
			return false;
		}
		return $res;
    }

	public function distribution($order_sn,$order_info,$uid=''){
        $users = M('users');
		if($this->uid === ''){
			$this->uid = $order_info['user_id'];
			$this->ulevel = $this->level;
		}else{
			$this->uid = $uid;
			$level = $users->where(['user_id'=>$uid])->getField('level');
			$user_level = M('user_level');
			$ulevel = $user_level->where(['level_id'=>$level])->find();
			$this->ulevel = $ulevel;
		}
		
        $user_where['user_id'] = $this->uid;
        $user_info = $users->where($user_where)->find();
        if(empty($user_info)){
        	echo '该用户不存在!'; return false;
        }
        if($user_info['is_lock'] == 1){
        	echo '该用户被冻结!'; return false;
        }
        //商品详情id字符链接、商品详情名称链接、可返佣用户、上级用户等级、上级用户信息
        $rec_id_str = $goods_name_str = $returned_user = $superior_level = $is_superior = '';
        //判断是否存在上级
        if(!empty($user_info['invitation_code_others'])){
        	
        	$superior = $users->where(['invitation_code_own'=>$user_info['invitation_code_others']])->find();
        	if(!empty($superior)){
        		$is_superior = $superior;
        	}
        	$user_level = M('user_level');
        	$superior_user_level = $user_level->where(['level_id'=>$superior['level']])->find();
        	if(!empty($superior_user_level)){
        		$superior_level = $superior_user_level;
        	}
        }
        //上升层级
        if(!empty($this->ulevel)){
        	$ascending_hierarchy = $this->ascending_hierarchy($this->ulevel);
        }
        //获得销售提成比例
        $sales_royalty_ratio = $this->sales_royalty_ratio($user_info['user_id'],$order_info['total_amount'],$is_superior);

        $time = time();
		//多个商品计算规格不一样
        $order_goods = M('order_goods');
        $goods_info = $order_goods->where(['order_id'=>$order_info['order_id']])->select();

        $count = count($goods_info);
        for ($i=0; $i < $count; $i++) { 

        	if($goods_info[$i]['is_agent'] == 1 && !empty($this->ulevel)){//是代理商品，是代理会员
        		echo '代理商不能重复购买此商品'; return false;
        	}

        	$total_amount = $goods_info[$i]['member_goods_price']*$goods_info[$i]['goods_num'];
        	$gvxx = 0;
        	if($goods_info[$i]['is_agent'] == 1 && empty($this->ulevel)){//是代理商品，不是代理会员
        		//用户晋级（成为代理，生成推荐码，记录生成代理时间）
        		$user_save['invitation_code_own'] = getyqcodes();
        		$user_save['upgrade_time'] = $time;
        		$user_save['level'] = 2;
        		//代理直推奖（初次成为代理，判断是否存在上级用户，返给上级）
        		if($is_superior !== '' && $superior_level !== ''){
        			$this->straightAward($users,$user_info['user_id'],$user_info['mobile'],$is_superior['user_id'],$order_info['order_id'],$order_sn,$total_amount,$goods_info[$i]['goods_name'],$goods_info[$i]['rec_id']);
        			//推广奖（初次成为代理，判断是否有可返佣推广奖用户，返给可返佣推广奖用户）
        			$this->returned_user($users,$user_info['user_id'],$is_superior,$total_amount,$order_info['order_id'],$order_sn,$user_info['mobile'],$goods_info[$i]['goods_name'],$goods_info[$i]['rec_id']);
        			//变成团队人数，团队人数无限加1
        			$gvxx = 1;
        			$users->where(['invitation_code_own'=>$user_info['invitation_code_others']])->setInc('gvxx');
        		}
        	}

        	if($goods_info[$i]['is_agent'] != 1 && !empty($this->ulevel)){//不是代理商品，是代理会员
        		//零售奖（购买代理半价）（判断此用户是否为代理，是添加零售奖记录）
        		$this->retail_award($user_info['user_id'],$total_amount,$goods_info[$i]['goods_name'],$order_sn,$order_info['order_id'],$goods_info[$i]['rec_id']);
        		//判断是否能晋级，如是升级操作
        		$this->be_promoted($users,$user_info['lower_stratum_number'],$user_info['gvxx'],$user_info['user_id'],$ascending_hierarchy);
        	}
        	$goods_name_str .= $goods_info[$i]['goods_name'].',';
        	$rec_id_str .= $goods_info[$i]['rec_id'].',';
        	
        }
        //销售提成1.5%-10% （判断是否存在上级用户，返给上级）、积分红利（无限极返给上级）、晋级奖（代理以上级别才有）
    	if($is_superior !== '' && !empty($sales_royalty_ratio)){
    		$this->sales_award($users,$sales_royalty_ratio,$order_info['total_amount'],$is_superior,$superior_level,$order_info['order_id'],$order_sn,$user_info['mobile'],trim($goods_name_str,','),trim($rec_id_str,','),$user_info['user_id'],$gvxx);
    	}

        if(!empty($user_save)){
        	$users_res = $users->where($user_where)->save($user_save);
        	if($users_res){
        		cookie("level_id", setEnCode($user_save['level'],'key'), 72*3600);
        	   	//升级日志
        		M('z_upgrade_log')->add(['user_id'=>$this->uid,'original_grade'=>'普通会员','current_grade'=>'代理会员','time'=>time()]);
        	}
        }
	}

	//销售提成与积分红利
	public function sales_award($users,$sales_royalty_ratio,$total_amount,$is_superior,$superior_level,$order_id,$order_sn,$mobile,$goods_name,$rec_id,$user_id,$gvxx){
		$z_monthly_bonus = M('z_monthly_bonus');
		//积分红利、下级是否满足300W
		$bonus = $count_in = 0;
		//下级消费，分给当前用户的销售提成
		$sales_award = $total_amount*($sales_royalty_ratio['royalty_ratio']/100);

		//如果下级销售额满足300W,此上级计算积分红利
		if(!empty($sales_royalty_ratio['count_in']) && $sales_royalty_ratio['count_in'] == 1){
			$count_in = 1;
			$bonus_bonus = $this->getConfig('bonus_bonus');//积分红利比例
			if(!empty($sales_royalty_ratio['total_performance'])){
				//积分红利金额
				$bonus = $sales_royalty_ratio['total_performance']*($bonus_bonus/100);
			}
		}
		//当月总销售额和应该金额累加
		$z_monthly_report = M('z_monthly_report');
		$time = time();
		$year = date('Y',$time);
		$month = date('m',$time);
		$report_where = ['year'=>$year,'month'=>$month];
		$monthly_report = $z_monthly_report->where($report_where)->find();
		$report_data['cashiering'] = $monthly_report['cashiering']+$bonus;
		$report_data['total_sales'] = $monthly_report['total_sales']+$sales_award;
		$z_monthly_report->where($report_where)->save($report_data);
		//上级用户的应返额 $sales_royalty_ratio['superior_monthly_bonus'] 上级月销售记录
		if(!empty($sales_royalty_ratio['superior_monthly_bonus'])){
			$count_in_two = 0;
			$data = [
				'subor_achieve' => $sales_royalty_ratio['superior_monthly_bonus']['subor_achieve']+$total_amount,
				'subor_total_achieve' => $sales_royalty_ratio['superior_monthly_bonus']['subor_total_achieve']+$total_amount,
				'achieve' => $sales_royalty_ratio['superior_monthly_bonus']['achieve']+$total_amount,
				'market' => $sales_royalty_ratio['superior_monthly_bonus']['market']+$count_in,
				'sales_award' => $sales_royalty_ratio['superior_monthly_bonus']['sales_award']+$sales_award,
				'bonus' => $sales_royalty_ratio['superior_monthly_bonus']['bonus']+$bonus,
			];
			if($sales_royalty_ratio['superior_monthly_bonus']['count_in'] != 1 && $data['achieve'] > 3000000){
				$count_in_two = $data['count_in'] = 1;
			}elseif($sales_royalty_ratio['superior_monthly_bonus']['count_in'] != 1 && $data['achieve'] == 3000000){
				$count_in_two  = $data['count_in'] = 1;
			}
			$z_monthly_bonus->where(['id'=>$sales_royalty_ratio['superior_monthly_bonus']['id']])->save($data);
		}else{
			$data = [
				'user_id' => $is_superior['user_id'],
				'market' => $count_in,
				'sales_award' => $sales_award,
				'bonus' => $bonus,
				'year' => $year,
				'month' => $month,
			];
			$data['subor_achieve'] = $data['subor_total_achieve'] = $data['achieve'] = $total_amount;
			if($data['achieve'] > 3000000 || $data['achieve'] == 3000000){
				$count_in_two  = $data['count_in'] = 1;
			}
			$z_monthly_bonus->add($data);
		}

		//奖金日志
		$account_log = M('account_log');
		if($bonus>0){
			$account_log->add(['user_id'=>$is_superior['user_id'],'bonus'=>$bonus,'change_time'=>time(),'desc'=>'下级团队销量达到300W，获得积分红利','order_sn'=>$order_sn,'order_id'=>$order_id,'subordinate_user_id'=>$user_id,'type'=>7,'order_goods_id'=>$rec_id]);
		}
		if($sales_award>0){
			$account_log->add(['user_id'=>$is_superior['user_id'],'sales_award'=>$sales_award,'change_time'=>time(),'desc'=>'下级购买商品，获得销售提成','order_sn'=>$order_sn,'order_id'=>$order_id,'subordinate_user_id'=>$user_id,'type'=>6,'order_goods_id'=>$rec_id]);
		}
		
		$level = $this->ulevel;
		$promotion_status = 0;
		if(!empty($level) && $superior_level !== '' && $superior_level['level_id'] !== 2 && $superior_level['level_id'] > 2 && $superior_level['level_id'] > $level['level_id']){

        	$promotion_status = $this->promotion_award($users,$superior_level['promotion_award'],$total_amount,$is_superior['user_id'],$mobile,$goods_name,$order_sn,$order_id,$user_id,$rec_id);

        }elseif($superior_level !== '' && $superior_level['level_id'] !== 2 && $superior_level['level_id'] > 2){

        	$promotion_status = $this->promotion_award($users,$superior_level['promotion_award'],$total_amount,$is_superior['user_id'],$mobile,$goods_name,$order_sn,$order_id,$user_id,$rec_id);
        }
        if($superior_level !== ''){
        	//判断是否能晋级，如是升级操作
			$this->be_promoted($users,$is_superior['lower_stratum_number'],$is_superior['gvxx'],$is_superior['user_id'],$superior_level);
        }
		
		if(!empty($is_superior['invitation_code_others'])){
			//无限下级分红
			$this->recursion($users,$z_monthly_bonus,$year,$month,$total_amount,$count_in_two,$bonus,$is_superior['invitation_code_others'],$superior_level,$promotion_status,$mobile,$goods_name,$order_sn,$order_id,$user_id,$rec_id,$gvxx);
		}

	}

	//无限下级分红
	public function recursion($users,$z_monthly_bonus,$year,$month,$total_amount,$count_in_two,$bonus,$invitation_code_others,$superior_level,$promotion_status,$mobile,$goods_name,$order_sn,$order_id,$user_id,$rec_id,$gvxx,$promotion_award_two=''){
		//无限下级分红
		$a_superior = $users->where(['invitation_code_own'=>$invitation_code_others])->find();
		if(!empty($a_superior)){
			//晋级奖分红比例、是否满300W业绩
			$promotion_award = $count_in  = 0;
			$superior_bonus_where = ['user_id'=>$a_superior['user_id'],'year'=>$year,'month'=>$month];
			$superior_monthly_bonus = $z_monthly_bonus->where($superior_bonus_where)->find();

			if(empty($superior_monthly_bonus)){
				$data = [
					'user_id' => $a_superior['user_id'],
					'market' => $count_in_two,
					'bonus' => $bonus,
					'year' => $year,
					'month' => $month,
				];
				$data['subor_total_achieve'] = $data['achieve'] = $total_amount;
				if($data['achieve'] > 3000000 || $data['achieve'] == 3000000){
					$count_in  = $data['count_in'] = 1;
				}
				$z_monthly_bonus->add($data);
			}else{
				$data = [
					'market' => $superior_monthly_bonus['market']+$count_in_two,
					'bonus' => $superior_monthly_bonus['bonus']+$bonus,
					'subor_total_achieve' =>$superior_monthly_bonus['subor_total_achieve']+$total_amount,
					'achieve' =>$superior_monthly_bonus['achieve']+$total_amount,
				];
				if($superior_monthly_bonus['count_in'] != 1 && $data['achieve'] > 3000000){
					$count_in = $data['count_in'] = 1;
				}elseif($superior_monthly_bonus['count_in'] != 1 && $data['achieve'] == 3000000){
					$count_in  = $data['count_in'] = 1;
				}
				$z_monthly_bonus->where(['user_id'=>$a_superior['user_id']])->save($data);
			}
			//奖金日志
			$account_log = M('account_log');
			if($bonus>0){
				$account_log->add(['user_id'=>$a_superior['user_id'],'bonus'=>$bonus,'change_time'=>time(),'desc'=>'下级团队销量达到300W，获得积分红利','order_sn'=>$order_sn,'order_id'=>$order_id,'subordinate_user_id'=>$user_id,'type'=>7,'order_goods_id'=>$rec_id]);
			}					
			//晋级奖
			$user_level = M('user_level');
        	$level = $user_level->where(['level_id'=>$a_superior['level']])->find();

        	if(!empty($level) && $level['level_id'] !== 2 && $level['level_id'] > 2 && $superior_level !== ''){

        		if($level['level_id'] > $superior_level['level_id'] && $promotion_status == 1){
        			if($promotion_award_two !== ''){
        				$promotion_award = $level['promotion_award'] - $promotion_award_two;
        			}else{
        				$promotion_award = $level['promotion_award'] - $superior_level['promotion_award'];
        			}
        		}elseif($level['level_id'] > $superior_level['level_id'] && $promotion_status == 0){

        			$promotion_award = $level['promotion_award'];
        		}elseif($level['level_id'] == $superior_level['level_id'] && $promotion_status == 1){

        			$promotion_award = $this->getConfig('peer_award');//同级奖比例
        		}
        		if($promotion_award !== 0){
        			$promotion_status = $this->promotion_award($users,$promotion_award,$total_amount,$a_superior['user_id'],$mobile,$goods_name,$order_sn,$order_id,$user_id,$rec_id,'同级奖');
        		}
        	}
        	if(!empty($level) && $level['level_id'] == 2 && $superior_level !== '' && $promotion_status == 1 && $promotion_award_two == ''){
        		$promotion_award_two = $superior_level['promotion_award'];
        	}
        	if($level !== ''){
        		if($gvxx == 1){
        			//变成团队人数，团队人数无限加1
        			$users->where(['invitation_code_own'=>$invitation_code_others])->setInc('gvxx');
        		}
	        	//判断是否能晋级，如是升级操作
				$this->be_promoted($users,$a_superior['lower_stratum_number'],$a_superior['gvxx'],$a_superior['user_id'],$level);
	        }

			if(!empty($a_superior['invitation_code_others'])){
				//无限下级分红
				$this->recursion($users,$z_monthly_bonus,$year,$month,$total_amount,$count_in,$bonus,$a_superior['invitation_code_others'],$level,$promotion_status,$mobile,$goods_name,$order_sn,$order_id,$user_id,$rec_id,$gvxx,$promotion_award_two);
			}
		}
	}

	//晋级奖
	public function promotion_award($users,$promotion_award,$total_amount,$uid,$mobile,$goods_name,$order_sn,$order_id,$user_id,$rec_id,$peer_award=''){
		$promotion_status = 0;
		$money = $total_amount*($promotion_award/100);
		if($money>0){
			$res = $users->where(['user_id'=>$uid])->setInc('user_money',$money);
			//奖金日志
			$type = 5;
			if($peer_award != ''){
				$peer_award = '晋级奖';
				$type = 3;
			}
			M('account_log')->add(['user_id'=>$uid,'user_money'=>$money,'change_time'=>time(),'desc'=>$mobile.'用户购买商品（'.$goods_name.'）,获得'.$peer_award,'order_sn'=>$order_sn,'order_id'=>$order_id,'subordinate_user_id'=>$user_id,'type'=>$type,'order_goods_id'=>$rec_id]);
			if($res){ $promotion_status = 1; }
			return $promotion_status;
		}
		
	}

	//获得销售提成比例
	public function sales_royalty_ratio($user_id,$total_price,$is_superior){
		$z_monthly_bonus = M('z_monthly_bonus');
		$royalty_ratio = 0;
		$info = [];
		$time = time();
		$year = date('Y',$time);
		$month = date('m',$time);

		//存入自己的销售额信息
		$bonus_where = ['user_id'=>$user_id,'year'=>$year,'month'=>$month];
		//自己的月销售记录
		$user_monthly_bonus = $z_monthly_bonus->where($bonus_where)->find();
		//如果自己的月销售记录存在
		if(!empty($user_monthly_bonus)){

			$data = [
				'consumption' => $user_monthly_bonus['consumption']+$total_price,
				'achieve'     => $user_monthly_bonus['achieve']+$total_price
			];
			if($user_monthly_bonus['count_in'] != 1 && $data['achieve'] > 3000000){
				$info['count_in'] = $data['count_in'] = 1;

			}elseif($user_monthly_bonus['count_in'] != 1 && $data['achieve'] == 3000000){
				$info['count_in'] = $data['count_in'] = 1;
			}
			$z_monthly_bonus->where($bonus_where)->save($data);
		}else{
			$data = [
				'user_id'     => $user_id,
				'consumption' => $total_price,
				'achieve'     => $total_price,
				'month'       => $month,
				'year'        => $year,
			];
			if($data['achieve'] > 3000000 || $data['achieve'] == 3000000){
				$info['count_in'] = $data['count_in'] = 1;
			}
			$z_monthly_bonus->add($data);
		}

		//当月总业绩累加
		$z_monthly_report = M('z_monthly_report');
		$monthly_report = $z_monthly_report->where(['year'=>$year,'month'=>$month])->find();
		
		if(empty($monthly_report)){
			$report_data = [
				'month' => $month,
				'year' => $year
			];
			$info['total_performance'] = $report_data['receipts'] = $total_price;
			$z_monthly_report->add($report_data);
		}else{
			$info['total_performance'] = $report_data['receipts'] = $monthly_report['receipts']+$total_price;
			$z_monthly_report->where(['year'=>$year,'month'=>$month])->save($report_data);
		}

		//如果当前用户存在上级，计入上级销售额中
		if($is_superior !== ''){
			$superior_bonus_where = ['user_id'=>$is_superior['user_id'],'year'=>$year,'month'=>$month];
			$superior_monthly_bonus = $z_monthly_bonus->where($superior_bonus_where)->find();
			$z_sales_award = M('z_sales_award');
			//最高销售比例
			$award_info = $z_sales_award->where('id>0')->order('achievement DESC')->select();
			//如果上级存在月销售记录
			if(!empty($superior_monthly_bonus)){
				$subor_achieve = $superior_monthly_bonus['subor_achieve']+$total_price;
				//上级月销售记录
				$info['superior_monthly_bonus'] = $superior_monthly_bonus;
			}else{
				$subor_achieve = $total_price;
			}
			$award_where['achievement'] = ['EGT',$subor_achieve];
			$award_info_two = $z_sales_award->where($award_where)->order('achievement DESC')->select();
			if(!empty($award_info_two)){
				//销售提成比例
				$royalty_ratio = $award_info_two[0]['royalty_ratio'];
			}else{
				if($subor_achieve > $award_info[0]['royalty_ratio'] || $subor_achieve == $award_info[0]['royalty_ratio']){
					$royalty_ratio = $award_info[0]['royalty_ratio'];
				}
			}
			$info['royalty_ratio'] = $royalty_ratio;
		}
		return $info;
	}

	//判断是否能晋级
    public function be_promoted($users,$lower_stratum_number,$gvxx,$user_id,$ascending_hierarchy){
    	$level_id = 0;
    	if($ascending_hierarchy['level_id'] == 10){
    		if($lower_stratum_number == $ascending_hierarchy['lower_rank'] && $gvxx == $ascending_hierarchy['team']){
    			$level_id = $ascending_hierarchy['level_id'];
    		}
    	}else{
    		if($lower_stratum_number == $ascending_hierarchy['lower_rank']){
    			$level_id = $ascending_hierarchy['level_id'];
    		}
    	}
    	if($level_id !== 0 && $ascending_hierarchy['level_id'] != $level_id && $level_id > $ascending_hierarchy['level_id']){
    		$users_res = $users->where(['user_id'=>$user_id])->save(['level'=>$level_id,'lower_stratum_number'=>0]);

    		if($users_res && $user_id == $this->uid){
	    		cookie("level_id", setEnCode($level_id,'key'), 72*3600);
	    	}
    		$level = $this->ulevel;
    		//升级日志
    		M('z_upgrade_log')->add(['user_id'=>$user_id,'original_grade'=>$level['level_name'],'current_grade'=>$ascending_hierarchy['level_name'],'time'=>time()]);
    	}
    }

    //上升层级
    public function ascending_hierarchy($level){
    	$user_level = M('user_level');
    	$level_info = $user_level->where('level_id>0')->order('level_id ASC')->select();
    	$count = count($level_info);
    	$return_level_info = [];
    	for ($i=0; $i < $count; $i++) { 
    		if($level_info[$i]['level_id'] == $level['level_id']){
    			$return_level_info[] = $level_info[$i+1];
    		}
    	}
    	return $return_level_info;
    }

	//零售奖
	public function retail_award($user_id,$total_amount,$goods_name,$order_sn,$order_id,$rec_id){
		//奖金日志
		M('account_log')->add(['user_id'=>$user_id,'user_money'=>$total_amount,'change_time'=>time(),'desc'=>'当前会员购买商品('.$goods_name.')，获得半价优惠','order_sn'=>$order_sn,'order_id'=>$order_id,'subordinate_user_id'=>$user_id,'type'=>4,'order_goods_id'=>$rec_id]);
	}

	//推广奖
	public function returned_user($users,$user_id,$is_superior,$total_amount,$order_id,$order_sn,$mobile,$goods_name,$rec_id){
		//判断上级是否有第一个代理
		$returned_user_id = '';
		if(!empty($is_superior['deputy_subordinate'])){
			$returned_user_id = $is_superior['user_id'];
		}else{
			if(!empty($is_superior['invitation_code_others'])){
				$two_superior = $users->where(['invitation_code_own'=>$is_superior['invitation_code_others']])->find();
				if(!empty($two_superior) && !empty($two_superior['deputy_subordinate'])){
					$returned_user_id = $two_superior['user_id'];
				}elseif(!empty($two_superior) && empty($two_superior['deputy_subordinate'])){
					// $main_market = $two_superior['user_id'];
				}
			}
			$main_market = $is_superior['user_id'];
			$users->where(['user_id'=>$main_market])->save(['deputy_subordinate'=>$user_id]);
		}

		if($returned_user_id !== ''){
			$users->where(['user_id'=>$user_id])->save(['extension_userid'=>$returned_user_id]);
			$this->obtain_bonus($users,'promotion_award',$total_amount,$returned_user_id,$mobile.'用户购买商品（'.$goods_name.'）升级为代理,获得推广奖',$order_sn,$order_id,$user_id,2,$rec_id);
		}
	}
	
	//直推奖
	public function straightAward($users,$user_id,$mobile,$superior_user_id,$order_id,$order_sn,$total_amount,$goods_name,$rec_id){
		$this->obtain_bonus($users,'straight_award',$total_amount,$superior_user_id,$mobile.'用户购买商品（'.$goods_name.'）升级为代理,获得直推奖',$order_sn,$order_id,$user_id,1,$rec_id);
	}

	//获得奖金及记录
	public function obtain_bonus($users,$proportion,$total_amount,$uid,$desc,$order_sn,$order_id,$user_id,$type,$rec_id){
		$proportion_two = $this->getConfig($proportion);
		$money = $total_amount*($proportion_two/100);
		$users->where(['user_id'=>$uid])->setInc('user_money',$money);
		//奖金日志
		M('account_log')->add(['user_id'=>$uid,'user_money'=>$money,'change_time'=>time(),'desc'=>$desc,'order_sn'=>$order_sn,'order_id'=>$order_id,'subordinate_user_id'=>$user_id,'type'=>$type,'order_goods_id'=>$rec_id]);
	}

}