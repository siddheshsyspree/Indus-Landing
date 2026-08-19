(function(){
  "use strict";

  /* Header scroll state + scroll progress bar */
  var header = document.getElementById('siteHeader');
  var scrollBar = document.getElementById('scrollBar');
  var backToTop = document.getElementById('backToTop');

  function onScroll(){
    var y = window.scrollY || document.documentElement.scrollTop;
    header.classList.toggle('is-scrolled', y > 40);
    backToTop.classList.toggle('is-visible', y > 600);

    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    var progress = docHeight > 0 ? (y / docHeight) * 100 : 0;
    scrollBar.style.width = progress + '%';
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* Scroll reveal — geometry-based so fast/instant scrolls (flicks, anchor
     jumps, key nav) can't skip an element past a threshold-crossing check
     the way IntersectionObserver's callback can. */
  var revealEls = Array.prototype.slice.call(document.querySelectorAll('.reveal'));
  var revealTicking = false;

  function checkReveal(){
    revealTicking = false;
    var limit = window.innerHeight * 0.92;
    revealEls = revealEls.filter(function(el){
      if (el.getBoundingClientRect().top < limit){
        el.classList.add('is-visible');
        return false;
      }
      return true;
    });
    if (!revealEls.length){
      window.removeEventListener('scroll', onRevealScroll);
      window.removeEventListener('resize', onRevealScroll);
    }
  }
  function onRevealScroll(){
    if (!revealTicking){
      revealTicking = true;
      setTimeout(checkReveal, 50);
    }
  }
  window.addEventListener('scroll', onRevealScroll, { passive: true });
  window.addEventListener('resize', onRevealScroll);
  window.addEventListener('load', checkReveal);
  window.addEventListener('pageshow', checkReveal);
  checkReveal();

  /* Membership tier carousel */
  var track = document.getElementById('tierTrack');
  var dotsWrap = document.getElementById('tierDots');
  var prevBtn = document.querySelector('.tier-prev');
  var nextBtn = document.querySelector('.tier-next');

  if (track){
    var cards = Array.prototype.slice.call(track.children);
    var perView = 3;
    var index = 0;

    function getPerView(){
      var w = window.innerWidth;
      if (w <= 640) return 1;
      if (w <= 980) return 2;
      return 3;
    }

    function maxIndex(){
      return Math.max(0, cards.length - getPerView());
    }

    function buildDots(){
      dotsWrap.innerHTML = '';
      var count = maxIndex() + 1;
      for (var i = 0; i < count; i++){
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        b.addEventListener('click', function(i){
          return function(){ goTo(i); };
        }(i));
        dotsWrap.appendChild(b);
      }
      updateDots();
    }

    function updateDots(){
      Array.prototype.forEach.call(dotsWrap.children, function(dot, i){
        dot.classList.toggle('is-active', i === index);
      });
    }

    function update(){
      perView = getPerView();
      var cardWidth = cards[0].getBoundingClientRect().width;
      var gap = 24;
      track.style.transform = 'translateX(-' + (index * (cardWidth + gap)) + 'px)';
      updateDots();
    }

    function goTo(i){
      index = Math.max(0, Math.min(i, maxIndex()));
      update();
    }

    prevBtn.addEventListener('click', function(){ goTo(index - 1); });
    nextBtn.addEventListener('click', function(){ goTo(index + 1); });

    var resizeTimer;
    window.addEventListener('resize', function(){
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function(){
        index = Math.min(index, maxIndex());
        buildDots();
        update();
      }, 150);
    });

    /* touch swipe */
    var startX = 0, isDown = false;
    track.addEventListener('touchstart', function(e){
      startX = e.touches[0].clientX; isDown = true;
    }, { passive: true });
    track.addEventListener('touchend', function(e){
      if (!isDown) return;
      isDown = false;
      var diff = e.changedTouches[0].clientX - startX;
      if (diff > 40) goTo(index - 1);
      else if (diff < -40) goTo(index + 1);
    });

    buildDots();
    update();
  }

  /* Smooth in-page nav for back-to-top / brand link already handled via CSS scroll-behavior */
})();
