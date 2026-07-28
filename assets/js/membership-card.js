(function(){
'use strict';

function drawQR(el){
    var value=el.getAttribute('data-qr');
    if(!value||!window.BSXMHQRCode)return;
    try{
        var qr=new window.BSXMHQRCode(-1,window.BSXMHQRErrorCorrectLevel.M);
        qr.addData(value);qr.make();
        var n=qr.getModuleCount(),svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('viewBox','0 0 '+n+' '+n);svg.setAttribute('role','img');
        svg.setAttribute('aria-label',el.getAttribute('aria-label')||'QR code');svg.setAttribute('shape-rendering','crispEdges');
        var bg=document.createElementNS(svg.namespaceURI,'rect');bg.setAttribute('width',n);bg.setAttribute('height',n);bg.setAttribute('fill','#fff');svg.appendChild(bg);
        var path='',r,c;for(r=0;r<n;r++)for(c=0;c<n;c++)if(qr.isDark(r,c))path+='M'+c+' '+r+'h1v1h-1z';
        var p=document.createElementNS(svg.namespaceURI,'path');p.setAttribute('d',path);p.setAttribute('fill','#000');svg.appendChild(p);
        el.innerHTML='';el.appendChild(svg);
    }catch(e){el.textContent='QR unavailable';}
}

function roundRect(ctx,x,y,w,h,r){
    r=Math.min(r,w/2,h/2);ctx.beginPath();ctx.moveTo(x+r,y);ctx.arcTo(x+w,y,x+w,y+h,r);ctx.arcTo(x+w,y+h,x,y+h,r);ctx.arcTo(x,y+h,x,y,r);ctx.arcTo(x,y,x+w,y,r);ctx.closePath();
}
function text(el,selector,fallback){var n=el.querySelector(selector);return n?n.textContent.trim():(fallback||'');}
function loadImage(url){
    return new Promise(function(resolve){
        if(!url){resolve(null);return;}
        var img=new Image();img.crossOrigin='anonymous';img.onload=function(){resolve(img);};img.onerror=function(){resolve(null);};img.src=url;
    });
}
function svgToImage(svg){
    return new Promise(function(resolve){
        if(!svg){resolve(null);return;}
        var xml=new XMLSerializer().serializeToString(svg),blob=new Blob([xml],{type:'image/svg+xml;charset=utf-8'}),url=URL.createObjectURL(blob),img=new Image();
        img.onload=function(){URL.revokeObjectURL(url);resolve(img);};img.onerror=function(){URL.revokeObjectURL(url);resolve(null);};img.src=url;
    });
}
function fitText(ctx,value,maxWidth,startSize,minSize,weight){
    var size=startSize;ctx.font=(weight||700)+' '+size+'px Arial, sans-serif';
    while(size>minSize&&ctx.measureText(value).width>maxWidth){size-=2;ctx.font=(weight||700)+' '+size+'px Arial, sans-serif';}
    return size;
}
function drawCover(ctx,img,x,y,w,h,r){
    if(!img)return false;var scale=Math.max(w/img.width,h/img.height),sw=w/scale,sh=h/scale,sx=(img.width-sw)/2,sy=(img.height-sh)/2;
    ctx.save();roundRect(ctx,x,y,w,h,r);ctx.clip();ctx.drawImage(img,sx,sy,sw,sh,x,y,w,h);ctx.restore();return true;
}
function initials(name){return name.split(/\s+/).filter(Boolean).slice(0,2).map(function(v){return v.charAt(0).toUpperCase();}).join('')||'M';}

async function downloadCard(button){
    var page=button.closest('.bsxmh-card-page'),card=page&&page.querySelector('.bsxmh-membership-card');if(!card)return;
    button.disabled=true;var original=button.textContent;button.textContent='Preparing PNG…';
    try{
        var scale=2,width=760*scale,height=430*scale,canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;
        var ctx=canvas.getContext('2d'),accent=getComputedStyle(card).getPropertyValue('--card-accent').trim()||'#183153';
        var brand=text(card,'.bsxmh-card-brand strong'),title=text(card,'.bsxmh-card-brand span'),status=text(card,'.bsxmh-card-status'),name=text(card,'.bsxmh-card-person h3'),footer=text(card,'footer');
        var details=card.querySelectorAll('.bsxmh-card-person p'),memberId='',memberSince='';
        details.forEach(function(row){var label=text(row,'span').toLowerCase(),value=text(row,'strong');if(label.indexOf('member id')>-1)memberId=value;else if(label.indexOf('member since')>-1)memberSince=value;});
        var code=text(card,'.bsxmh-card-qr-wrap code'),scan=text(card,'.bsxmh-card-qr-wrap small');
        var logoEl=card.querySelector('.bsxmh-card-brand img'),photoEl=card.querySelector('.bsxmh-card-photo'),qrSvg=card.querySelector('.bsxmh-qr-code svg');
        var images=await Promise.all([loadImage(logoEl&&logoEl.src),loadImage(photoEl&&photoEl.src),svgToImage(qrSvg)]),logo=images[0],photo=images[1],qr=images[2];
        var grad=ctx.createLinearGradient(0,0,width,height);grad.addColorStop(0,accent);grad.addColorStop(1,'#0b1728');roundRect(ctx,0,0,width,height,48);ctx.fillStyle=grad;ctx.fill();
        ctx.strokeStyle='rgba(255,255,255,.15)';ctx.lineWidth=2;ctx.beginPath();ctx.moveTo(0,150);ctx.lineTo(width,150);ctx.stroke();
        if(logo){ctx.fillStyle='#fff';roundRect(ctx,56,35,108,108,24);ctx.fill();ctx.drawImage(logo,68,47,84,84);} 
        ctx.fillStyle='#fff';ctx.font='700 40px Arial, sans-serif';ctx.fillText(brand,logo?190:58,72);ctx.globalAlpha=.76;ctx.font='400 27px Arial, sans-serif';ctx.fillText(title,logo?190:58,116);ctx.globalAlpha=1;
        ctx.font='700 25px Arial, sans-serif';var sw=ctx.measureText(status).width+54;ctx.fillStyle=status.toLowerCase()==='active'?'#d1fae5':'rgba(255,255,255,.18)';roundRect(ctx,width-sw-58,50,sw,58,29);ctx.fill();ctx.fillStyle=status.toLowerCase()==='active'?'#065f46':'#fff';ctx.fillText(status,width-sw-31,88);
        var px=62,py=194,pw=232,ph=232;if(!drawCover(ctx,photo,px,py,pw,ph,34)){ctx.fillStyle='rgba(255,255,255,.18)';roundRect(ctx,px,py,pw,ph,34);ctx.fill();ctx.fillStyle='#fff';ctx.textAlign='center';ctx.font='700 82px Arial, sans-serif';ctx.fillText(initials(name),px+pw/2,py+145);ctx.textAlign='left';}
        ctx.strokeStyle='rgba(255,255,255,.82)';ctx.lineWidth=8;roundRect(ctx,px,py,pw,ph,34);ctx.stroke();
        var tx=334;ctx.fillStyle='rgba(255,255,255,.72)';ctx.font='400 24px Arial, sans-serif';ctx.fillText('Member',tx,218);ctx.fillStyle='#fff';fitText(ctx,name,540,48,30,700);ctx.fillText(name,tx,274);
        ctx.fillStyle='rgba(255,255,255,.68)';ctx.font='400 23px Arial, sans-serif';ctx.fillText('Member ID',tx,335);ctx.fillStyle='#fff';ctx.font='700 26px Arial, sans-serif';ctx.fillText(memberId,tx+175,335);
        if(memberSince){ctx.fillStyle='rgba(255,255,255,.68)';ctx.font='400 23px Arial, sans-serif';ctx.fillText('Member Since',tx,385);ctx.fillStyle='#fff';ctx.font='700 25px Arial, sans-serif';ctx.fillText(memberSince,tx+175,385);}
        var qx=width-390,qy=184,qw=326,qh=326;ctx.fillStyle='#fff';roundRect(ctx,qx,qy,qw,qh,34);ctx.fill();if(qr)ctx.drawImage(qr,qx+27,qy+22,272,272);
        ctx.fillStyle='#111';ctx.textAlign='center';ctx.font='400 19px Arial, sans-serif';ctx.fillText(scan,qx+qw/2,qy+315);ctx.font='700 20px monospace';ctx.fillText(code,qx+qw/2,qy+348);ctx.textAlign='left';
        ctx.fillStyle='rgba(0,0,0,.18)';ctx.fillRect(0,height-80,width,80);ctx.fillStyle='rgba(255,255,255,.8)';ctx.textAlign='center';fitText(ctx,footer,width-120,22,16,400);ctx.fillText(footer,width/2,height-31);ctx.textAlign='left';
        var filename=button.getAttribute('data-filename')||'Membership-Card.png';
        canvas.toBlob(function(blob){if(!blob)throw new Error('PNG generation failed');var url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},1500);},'image/png',1);
    }catch(e){window.alert('Unable to create the PNG. Please try again after the card images finish loading.');}
    finally{button.disabled=false;button.textContent=original;}
}

document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.bsxmh-qr-code').forEach(drawQR);document.querySelectorAll('.bsxmh-download-card').forEach(function(button){button.addEventListener('click',function(){downloadCard(button);});});});
})();
