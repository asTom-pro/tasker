$(function () {
  function urlCopy() {
    $('body').append('<textarea id="currentURL" style="position:fixed;left:-100%;">' + location.href + '</textarea>');
    $('#currentURL').select();
    document.execCommand('copy');
    $('#currentURL').remove();
  }
  $('.modal-bg-black').height(document.documentElement.scrollHeight);
  $('.js-btn-invite').click(function () {
    urlCopy();
    link_text = $(this).text();
    $(this).text('URLをコピーしました。');
    const toggleText = () => {
      $(this).text(link_text);
    }
    setTimeout(toggleText, 5000);
  });

  $('.js-modal-invite-open').click(function(){
    $('.modal-share').toggleClass('is-active');
    $('.modal-bg-black').toggleClass('is-active');
  });
  $('.js-modal-close').click(function(){
    $('.modal-share').toggleClass('is-active');
    $('.modal-bg-black').toggleClass('is-active');
  });
  $('.js-modal-bg').click(function(){
    $(this).toggleClass('is-active');
    $('.modal-share').toggleClass('is-active');
  });

  $(".js-btn-invite-open").click(function () {
    $(".invite-container").toggleClass("d-none");
  });

  $('.js-url-copy').click(function () {
    urlCopy();
    $('#js-msg-container').show();
    $('.msg').text('コピーしました！');
    $('#js-msg-container').fadeOut(3000);
  });

  // 文字数カウント
  var count = $(this).val().length;
  $('.show-count').text(count);
  $('#js-count').keyup(function(){
    var count = $(this).val().length;
    $('.show-count').text(count);
    if( count > 140){
      $('.input-num').addClass('is-over-count').removeClass('is-in-count');
      $('.input-num').removeClass('is-in-count');
    }else{
      $('.input-num').addClass('is-in-count').removeClass('is-over-count');
      $('.input-num').removeClass('is-over-count');
    }
  });

  $('.js-toggle-header-link').on('click',function(){
    var headerHeight = $('.header').height();
    $('.header-link-container').css('top',headerHeight);
    $('.js-show-header-link').toggle();
  })

  $('.btn-search').on('click',function(){
    var headerHeight = $('.header').height();
    $('.search-container').css('top',headerHeight);
    $('.js-toggle-search-container').toggle();
  })
  $('.task-table tbody').hide();
  $('.js-open-table').click(function(){
    $('.js-closing-table').toggleClass('is-active');
    $('.js-opening-table').toggleClass('is-active');
    $('.task-table tbody').slideToggle();
  })

})
function login_modal($title){
  $('#modal').load('./loginModal.php?from_path='+location.pathname+location.search, function(){
    $('#modal .modal-title').text($title);
  });
}

$('#msg-container').fadeOut(3000);