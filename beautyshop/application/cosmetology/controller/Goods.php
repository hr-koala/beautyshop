<?php
namespace app\cosmetology\controller;
use app\common\logic\GoodsLogic;
use think\Page;
use think\Verify;
use think\Image;
use think\Db;
class Goods extends Base {
	//商品详情
    public function details(){
    	C('TOKEN_ON',true);
    	$goodsLogic = new GoodsLogic();
        $goods_id = I("get.id/d");
        $goodsModel = new \app\common\model\Goods();
        $goods = $goodsModel::get($goods_id);
        if(empty($goods) || ($goods['is_on_sale'] == 0) || ($goods['is_virtual']==1 && $goods['virtual_indate'] <= time())){
            echo"<script>alert('此商品不存在或者已下架');history.go(-1);</script>";
        }
        //用户浏览记录
        $user_id = $this->user_id;
        if ($user_id>0) {
            $goodsLogic->add_visit_log($user_id, $goods);
            //当前用户收藏
            $collect = M('goods_collect')->where(array("goods_id"=>$goods_id ,"user_id"=>$user_id))->count();
        }else{
            $user_id = $collect = 0;
        }
        //商品品牌
        if($goods['brand_id']){
            $brnad = M('brand')->where("id", $goods['brand_id'])->find();
            $goods['brand_name'] = $brnad['name'];
        }
         // 商品 图册
        $goods_images_list = M('GoodsImages')->where("goods_id", $goods_id)->select();
         //获取商品规格
		$filter_spec = $goodsLogic->get_spec($goods_id);
		// 规格 对应 价格 库存表
		$spec_goods_price  = M('spec_goods_price')->where("goods_id", $goods_id)->getField("key,price,store_count,item_id");
        // dump($filter_spec);dump($spec_goods_price);die;
        //商品收藏数
        $goods_collect_count = M('goods_collect')->where(array("goods_id"=>$goods_id))->count(); 
        //商品属性
        $attribute = [['name'=>'企业认证','status'=>1],['name'=>'新品上市','status'=>$goods['is_new']],['name'=>'热门推荐','status'=>$goods['is_recommend']],['name'=>'快递送货','status'=>1]];
        $this->assign('collect',$collect);
        $this->assign('user_id',$user_id);
        $this->assign('attribute',$attribute);
        $this->assign('filter_spec',json_encode($filter_spec,true));//规格参数
        $this->assign('goods_images_list',$goods_images_list);//商品缩略图
        $this->assign('goods_collect_count',$goods_collect_count); //商品收藏人数
        $this->assign('spec_goods_price', json_encode($spec_goods_price,true)); // 规格 对应 价格 库存表
        $this->assign('goods',$goods->toArray());
    	return $this->fetch();
    }

    //商品分类
    public function categoryList(){
    	$id_path = I("get.id_path/d");
    	//一级分类
    	$goods_category = M('goods_category');
	    $cat_list = $this->topCategory($goods_category,1);

    	if(!empty($id_path)){
    		$pitch_on = $id_path;
    	}else{
    		$pitch_on = $cat_list[0]['parent_id_path'];
    	}
    	$catid = $this->get_catid($goods_category,$pitch_on);
    	//选中分类商品
    	$where = ['is_on_sale'=>1 , 'cat_id'=>['in',$catid] , 'is_virtual' => ['exp' ,"=0 or virtual_indate > ".time()]];
	 	$goods = $this->select_goods($where,'sort ASC');
	    $this->assign('cat_list',$cat_list);//一级分类
	    $this->assign('pitch_on',$pitch_on);//选中分类
	    $this->assign('goods',$goods);//选中商品
        return $this->fetch();
    }

    //一级分类
    public function topCategory($goods_category,$level){
        return $goods_category->cache(true)->where(['is_show' => 1,'level'=>$level])->order('sort_order')->select();
    }

    public function ajaxGoods(){
        if(IS_AJAX){
            $parent_id = I('post.parent_id');
            $catid = $this->get_catid(M('goods_category'),$parent_id);
            $where = ['is_on_sale'=>1 , 'cat_id'=>['in',$catid] , 'is_virtual' => ['exp' ,"=0 or virtual_indate > ".time()]];
            $goods = $this->select_goods($where,'sort ASC');
            $this->assign('goods',$goods);
            echo json_encode(['status'=>1,'msg'=>'登录成功','info'=>$this->fetch('goods/ajaxGoods')]);exit;
        }
    }

    //获取商品分类id字符串
    public function get_catid($goods_category,$pitch_on){
        $cat_info = $goods_category->cache(true)->where(['parent_id_path'=>['like',"%$pitch_on%"],'is_show' => 1])->field('id')->select();
        return getString($cat_info,'id');
    }

    //商品搜索页
    public function search(){
        if(IS_AJAX){
            $postData = I("post.");
        }else{
            $postData = I("get.");
            if(!empty($postData['is_new'])){
                $where['is_new'] = 1;
            }
            if(!empty($postData['is_hot'])){
                $where['is_hot'] = 1;
            }
            if(!empty($postData['is_recommend'])){
                $where['is_recommend'] = 1;
            }
        }
        $goods_category = M('goods_category');
        if(!empty($postData['id_path'])){//分类名称家族史
            $catid = $this->get_catid($goods_category,$postData['id_path']);
            $where['cat_id'] = ['in',$catid];
        }

        if(!empty($postData['attribute'])){
            $attribute = explode(',', trim($postData['attribute'],','));
            $count = count($attribute);
            for ($i=0; $i < $count; $i++) { 
                $where[$attribute[$i]] = 1;
            }
        }
        if(!empty($postData['name'])){//商品名称
            $where['goods_name'] = ['like',"%".$postData['name']."%"];
        }
        if(!empty($postData['sales_volume'])){//商品销量
            $order = 'sales_sum '.$postData['sales_volume'];
        }elseif(!empty($postData['price'])){//商品价格
            $order = 'shop_price '.$postData['price'];
        }else{
            $order = 'sort ASC';
        }
        $where['is_on_sale'] = 1;
        $where['is_virtual'] = ['exp' ,"=0 or virtual_indate > ".time()];

       /* if(IS_AJAX){
        dump($where);die;
            dump($where);dump($postData);dump($order);die;
        }*/
        
        $goods = $this->select_goods($where,$order);
        $this->assign('goods',$goods);
        if(IS_AJAX){
            // dump($goods);
            echo json_encode(['status'=>1,'msg'=>'成功','info'=>$this->fetch('goods/ajaxsearch')]);exit;
        }else{
            $tabulation['topCategory'] = $this->topCategory($goods_category,1);
            $tabulation['twoCategory'] = $this->topCategory($goods_category,2);
            $attribute = [['name'=>'新品上市','value'=>'is_new'],['name'=>'推荐商品','value'=>'is_recommend'],['name'=>'热卖商品','value'=>'is_hot']];
            $this->assign('attribute',$attribute);//属性标签
            $this->assign('tabulation',$tabulation);//一二级分类
            return $this->fetch();
        }
        
    }

    //商品收藏
    public function follow(){
        $postData = I('post.');
        $goods_collect = M('goods_collect');
        $goods = M('goods');
        if($postData['follow'] == 1){
            $goods->where('goods_id', $postData['goods_id'])->setInc('collect_sum');
            $data = [
                'user_id' => $this->user_id,
                'goods_id' => $postData['goods_id'],
                'add_time' => time()
            ];
            $res = $goods_collect->add($data);
            if($res){
                echo json_encode(['status'=>1,'msg'=>'收藏成功']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'收藏失败']);exit;
            }
        }elseif($postData['follow'] == 0){
            $goods->where('goods_id', $postData['goods_id'])->setDec('collect_sum');
            $res = $goods_collect->where(['user_id'=>$this->user_id,'goods_id'=>$postData['goods_id']])->delete();
            if($res){
                echo json_encode(['status'=>1,'msg'=>'已取消收藏']);exit;
            }else{
                echo json_encode(['status'=>0,'msg'=>'取消失败']);exit;
            }
        }
    }

    
}