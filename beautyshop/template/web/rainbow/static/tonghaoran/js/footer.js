$(function(){
    var isdaili = true;
    var html =  '<ul>'
                    +'<li><a href="index1.html" title=""><div class="icon i1"></div><p>首页</p></a></li>'
                    +'<li><a href="classify.html" title=""><div class="icon i2"></div><p>分类</p></a></li>'
                    +'<li><a href="cart.html" title=""><div class="icon i3"></div><p>购物袋</p></a></li>';
    if(isdaili){
        html += '<li><a href="distr_commission.html" title=""><div class="icon i4"></div><p>代理</p></a></li>';
    }
    html += '<li><a href="mine.html" title=""><div class="icon i5"></div><p>我的</p></a></li></ul>';
    footerNav = (footerNav - 1) || 0;
    $('#footer-nav').html(html);
    $('#footer-nav ul li').eq(footerNav).addClass('active')
})