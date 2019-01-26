$('.page-search .class-list ul').on('click',function(){
    $('.page-search .sort').hide();
    $('.page-search .sort').eq($(this).index()).show();
    $('.class-list ul').eq($(this).index()).addClass("class-on").siblings().removeClass();
    $(this).attr("class","class-on");
    var foo= $(".class-list ul[class='']");
    $(foo).each(function() {
        $(foo).attr("class","class-off");
    })
    $('.class-list .class-off .sort li').attr("class","");
})
$('.page-search .class-list ul .sort li').on('click',function(){
    var css = $(this).attr("class");
    if(css === 'on'){
        $(this).attr("class","off");
    }else{
        $('ul .sort li').attr("class","off");
        $(this).attr("class","on");
    }
})    
var s = 1;
$('.types .filter').click(function(){
    s++;
    if(s%2 == 0){
        $('.page-search .mask').show()
        $(this).css({
            'color':'#e14594',
            "background-image":"url(/template/web/rainbow/static/xiong/img/xiahong.png')"
        });
    }else{
        filterhide()
    }
    shangxia('.types .volume')
    shangxia('.types .price')
})

function filterhide(){
    if(s%2 == 0){
        s += 1
    }
    $('.page-search .mask').hide();
    $('.types .filter').css({
        'color':'#000000',
        "background-image":"url(/template/web/rainbow/static/xiong/img/xia.png)"
    });
}

var t = 1
function pricehide(indx){
    t++;
    if(t%2 == 0){
        $(indx).css({
            "background-image":"url(/template/web/rainbow/static/xiong/img/shang.png)"
        });
    }else{
        $(indx).css({
            "background-image":"url(/template/web/rainbow/static/xiong/img/xia.png)"
        });
    }
}

$('.selects .classification span').on('click',function(){
    var css = $(this).attr('class');
    if (css == "cation_on") {
        $(this).attr("class","cation_off");
    } else {
        $(this).attr("class","cation_on");
    }
})

var i = 1;
$('.types .price').click(function(){
    filterhide()
    pricehide('.types .price')
    shangxia('.types .volume')
    i++;
    if(i%2 == 0){
        var price = 'ASC';
    }else{
        var price = 'DESC';
    }
    var name = $('.goodsname').val();
    $.post("/cosmetology/Goods/search.html", {price:price,name:name},function(datas){
        var values = JSON.parse(datas);
        $('.conter').html(values['info']);
    });
})

var l = 1;
$('.types .volume').click(function(){
    filterhide()
    pricehide('.types .volume')
    shangxia('.types .price')
    l++;
    if(l%2 == 0){
        var sales_volume = 'ASC';
    }else{
        var sales_volume = 'DESC';
    }
    var name = $('.goodsname').val();
    $.post("/cosmetology/Goods/search.html", {sales_volume:sales_volume,name:name},function(datas){
        var values = JSON.parse(datas);
        $('.conter').html(values['info']);
    });
})


function shangxia(indx){
    $(indx).css({
        "background-image":"url(/template/web/rainbow/static/xiong/img/shangxia.png)"
    });
}


$('.types .complex').click(function(){
    filterhide()
    window.location.href = "/cosmetology/Goods/search.html";
})

$('.btn .cancel').click(function(){
    filterhide()
})

$('.btn .confirm').click(function(){
    var foo= $(".classification span[class='cation_on']");
    var attribute = '';
    $(foo).each(function() {
        attribute += $(this).attr('id')+',';
    })
    var name = $('.goodsname').val();
    var id_path = $('.class-list ul .sort .on').attr('id');
    if(id_path !== '' && id_path !== undefined){
        id_path = id_path.substring(id_path.length,4);
    }else{
        id_path = '';
    }
    $.post("/cosmetology/Goods/search.html", {attribute:attribute,name:name,id_path:id_path},function(datas){
        var values = JSON.parse(datas);
        filterhide()
        $('.conter').html(values['info']);
    });
})


