$(function(){
    
    var html =  '<ul>'
                    +'<li><a href="/cosmetology/Index/index.html" title=""><div class="icon i1"></div><p>首页</p></a></li>'
                    +'<li><a href="/cosmetology/Goods/categoryList.html" title=""><div class="icon i2"></div><p>分类</p></a></li>'
                    +'<li><a href="/cosmetology/Cart/cartList.html" title=""><div class="icon i3"></div><p>购物袋</p></a></li>';
    if(isdaili){
        html += '<li><a href="/cosmetology/Agent/index.html" title=""><div class="icon i4"></div><p>代理</p></a></li>';
    }
    html += '<li><a href="/cosmetology/Mine/index.html" title=""><div class="icon i5"></div><p>我的</p></a></li></ul>';
    footerNav = (footerNav - 1) || 0;
    $('#footer-nav').html(html);
    $('#footer-nav ul li').eq(footerNav).addClass('active')
})