// demo/public/assets/app.js — dica flutuante e realce do sociograma (sem bibliotecas).
(function () {
  'use strict';

  // ---- dica flutuante para qualquer elemento com data-tip ----
  var tip = document.getElementById('tip');
  function place(ev) {
    var x = ev.clientX + 14, y = ev.clientY + 14;
    if (x + tip.offsetWidth > window.innerWidth - 8) x = ev.clientX - tip.offsetWidth - 10;
    if (y + tip.offsetHeight > window.innerHeight - 8) y = ev.clientY - tip.offsetHeight - 10;
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
  }
  document.addEventListener('mouseover', function (ev) {
    var el = ev.target.closest('[data-tip]');
    if (!el || !tip) return;
    tip.textContent = el.getAttribute('data-tip');
    tip.hidden = false;
    place(ev);
  });
  document.addEventListener('mousemove', function (ev) {
    if (tip && !tip.hidden) place(ev);
  });
  document.addEventListener('mouseout', function (ev) {
    if (tip && ev.target.closest('[data-tip]')) tip.hidden = true;
  });

  // ---- sociograma: ao passar o mouse, destaca o aluno e seus vínculos ----
  document.querySelectorAll('svg.sociogram').forEach(function (svg) {
    var lines = svg.querySelectorAll('line');
    var nodes = {};
    svg.querySelectorAll('.node').forEach(function (n) { nodes[n.dataset.id] = n; });

    function focus(id) {
      svg.classList.add('focus');
      nodes[id].classList.add('hl');
      lines.forEach(function (l) {
        if (l.dataset.a === id || l.dataset.b === id) {
          l.classList.add('hl');
          var other = l.dataset.a === id ? l.dataset.b : l.dataset.a;
          if (nodes[other]) nodes[other].classList.add('hl');
        }
      });
    }
    function clear() {
      svg.classList.remove('focus');
      svg.querySelectorAll('.hl').forEach(function (el) { el.classList.remove('hl'); });
    }
    Object.keys(nodes).forEach(function (id) {
      nodes[id].addEventListener('mouseenter', function () { focus(id); });
      nodes[id].addEventListener('mouseleave', clear);
      nodes[id].addEventListener('focus', function () { focus(id); });
      nodes[id].addEventListener('blur', clear);
    });
  });

  // ---- formulário do aluno: respeita o limite de escolhas por pergunta ----
  document.querySelectorAll('[data-max]').forEach(function (box) {
    var max = parseInt(box.dataset.max, 10);
    box.addEventListener('change', function () {
      var checked = box.querySelectorAll('input:checked').length;
      box.querySelectorAll('input:not(:checked)').forEach(function (i) { i.disabled = checked >= max; });
    });
  });

  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });
})();
