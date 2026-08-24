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

  /* Register Interest form */
  var registerForm = document.getElementById('registerForm');
  if (registerForm){
    registerForm.addEventListener('submit', function(e){
      e.preventDefault();
      if (!registerForm.checkValidity()){
        registerForm.reportValidity();
        return;
      }
      var btn = registerForm.querySelector('.form-submit');
      var originalLabel = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Submitting…';

      var data = {
        fullName: registerForm.fullName.value,
        companyName: registerForm.companyName.value,
        city: registerForm.city.value,
        age: registerForm.age.value,
        designation: registerForm.designation.value,
        referral: registerForm.referral.value,
        mobile: registerForm.mobile.value,
        email: registerForm.email.value
      };

      fetch('register-landing.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      })
        .then(function(res){ return res.json(); })
        .then(function(json){
          if (!json || json.status !== 'success') throw new Error('submit-failed');
          btn.textContent = 'Thank You — We’ll Be In Touch';
          registerForm.reset();
          setTimeout(function(){
            window.location.href = 'https://www.theindusclub.com/';
          }, 1200);
        })
        .catch(function(){
          btn.disabled = false;
          btn.textContent = originalLabel;
          alert('Something went wrong submitting your interest. Please try again, or email contact@theindusclub.com directly.');
        });
    });
  }

  /* Smooth in-page nav for back-to-top / brand link already handled via CSS scroll-behavior */
})();
