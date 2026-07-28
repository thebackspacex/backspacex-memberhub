(function(){
'use strict';
function ready(fn){if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);}else{fn();}}
ready(function(){
 document.querySelectorAll('[data-bsxmh-toggle-password]').forEach(function(button){
  button.addEventListener('click',function(){
   var id=button.getAttribute('data-bsxmh-toggle-password');
   var input=document.getElementById(id);
   if(!input)return;
   var showing=input.type==='text';
   input.type=showing?'password':'text';
   button.textContent=showing?'Show':'Hide';
   button.setAttribute('aria-label',showing?'Show password':'Hide password');
  });
 });
 var password=document.querySelector('[data-bsxmh-password]');
 var output=document.querySelector('[data-bsxmh-strength]');
 if(password&&output){
  var update=function(){
   var value=password.value||'';
   var score=0;
   if(value.length>=8)score++;
   if(value.length>=12)score++;
   if(/[a-z]/.test(value)&&/[A-Z]/.test(value))score++;
   if(/\d/.test(value))score++;
   if(/[^A-Za-z0-9]/.test(value))score++;
   output.className='bsxmh-password-strength';
   if(!value){output.textContent='Use at least 8 characters.';return;}
   if(score<=1){output.textContent='Password strength: Weak';output.classList.add('is-weak');}
   else if(score<=3){output.textContent='Password strength: Medium';output.classList.add('is-medium');}
   else{output.textContent='Password strength: Strong';output.classList.add('is-strong');}
  };
  password.addEventListener('input',update);update();
 }
});
})();


// v1.5.0-beta6 member portal interactions.
document.addEventListener('click', function (event) {
    var preset = event.target.closest('.bsxmh-amount-presets [data-amount]');
    if (preset) {
        var form = preset.closest('form');
        var amount = form ? form.querySelector('[data-bsxmh-amount]') : null;
        if (amount) { amount.value = preset.getAttribute('data-amount'); amount.dispatchEvent(new Event('input', { bubbles: true })); amount.focus(); }
    }
    var selectAll = event.target.closest('[data-bsxmh-select-unpaid]');
    if (selectAll) {
        var container = selectAll.closest('form');
        if (container) container.querySelectorAll('.bsxmh-payment-month input[type="checkbox"]:not(:disabled)').forEach(function (box) { box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true })); });
    }
});
function bsxmhUpdateSelectedTotal(form) {
    if (!form) return;
    var output = form.querySelector('[data-bsxmh-selected-total]');
    if (!output) return;
    var fee = parseFloat(form.getAttribute('data-monthly-fee') || '0');
    var count = form.querySelectorAll('.bsxmh-payment-month input[type="checkbox"]:checked').length;
    output.textContent = '৳' + (fee * count).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
document.addEventListener('change', function (event) { if (event.target.matches('.bsxmh-payment-month input[type="checkbox"]')) bsxmhUpdateSelectedTotal(event.target.closest('form')); });
document.querySelectorAll('.bsxmh-modern-payment-form').forEach(bsxmhUpdateSelectedTotal);
