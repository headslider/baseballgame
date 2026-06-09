/*
 * admin_json_export_ui.js v829
 * 管理画面「問題管理」タブ内にJSONエクスポート導線を追加する補助UI。
 * 重要：ダッシュボード上部のタブメニューには挿入しない。
 */
(function () {
  'use strict';

  var PANEL_ID = 'question-json-export-panel';

  function makeUrl(mode, token) {
    var params = new URLSearchParams();
    params.set('mode', mode);
    if (token) params.set('token', token);
    return 'api/admin_questions_json_export.php?' + params.toString();
  }

  function createButton(label, mode, tokenInput) {
    var a = document.createElement('a');
    a.href = makeUrl(mode, tokenInput.value.trim());
    a.textContent = label;
    a.className = 'secondary small admin-json-export-btn';
    a.setAttribute('download', '');
    a.style.display = 'inline-block';
    a.style.margin = '4px 8px 4px 0';
    a.style.padding = '8px 12px';
    a.style.borderRadius = '8px';
    a.style.border = '1px solid #c8d3e1';
    a.style.background = '#fff';
    a.style.textDecoration = 'none';
    a.style.fontWeight = '700';
    a.addEventListener('click', function () {
      a.href = makeUrl(mode, tokenInput.value.trim());
    });
    return a;
  }

  function createPanel() {
    var panel = document.createElement('div');
    panel.id = PANEL_ID;
    panel.className = 'csv-admin-section admin-json-export-section';
    panel.style.marginTop = '14px';
    panel.style.paddingTop = '14px';
    panel.style.borderTop = '1px solid #dbe3ee';

    var title = document.createElement('h4');
    title.textContent = '問題データJSONエクスポート';
    title.style.margin = '0 0 4px';
    panel.appendChild(title);

    var desc = document.createElement('div');
    desc.className = 'muted';
    desc.textContent = '今後の問題修正・照合用に、CSVではなくJSON形式で問題データを出力します。修正作業には「完全照合用JSON」を優先して使用してください。';
    panel.appendChild(desc);

    var tokenWrap = document.createElement('label');
    tokenWrap.textContent = '任意トークン：';
    tokenWrap.style.display = 'block';
    tokenWrap.style.margin = '10px 0 8px';
    tokenWrap.style.fontWeight = '700';

    var tokenInput = document.createElement('input');
    tokenInput.type = 'password';
    tokenInput.placeholder = 'scores/admin_json_export_token.txt を使う場合のみ入力';
    tokenInput.autocomplete = 'off';
    tokenInput.style.minWidth = '320px';
    tokenInput.style.maxWidth = '100%';
    tokenInput.style.marginLeft = '8px';
    tokenWrap.appendChild(tokenInput);
    panel.appendChild(tokenWrap);

    var buttonWrap = document.createElement('div');
    buttonWrap.className = 'row';
    buttonWrap.style.gap = '8px';
    buttonWrap.style.alignItems = 'center';
    buttonWrap.appendChild(createButton('完全照合用JSONをダウンロード', 'master', tokenInput));
    buttonWrap.appendChild(createButton('出題用questions.jsonをダウンロード', 'active', tokenInput));
    panel.appendChild(buttonWrap);

    var note = document.createElement('div');
    note.className = 'muted';
    note.style.marginTop = '8px';
    note.textContent = '完全照合用JSONには停止中問題も含まれます。ゲーム反映用は出題用questions.jsonです。';
    panel.appendChild(note);

    return panel;
  }

  function removeMisplacedPanels() {
    var panels = Array.prototype.slice.call(document.querySelectorAll('#' + PANEL_ID));
    panels.forEach(function (p) {
      if (!p.closest('#tab-question-management')) {
        p.parentNode && p.parentNode.removeChild(p);
      }
    });
  }

  function insertPanel() {
    removeMisplacedPanels();

    var existing = document.querySelector('#tab-question-management #' + PANEL_ID);
    if (existing) return true;

    // 必ず問題管理タブの中だけを対象にする。タブボタン「問題管理」は対象にしない。
    var exportPanel = document.querySelector('#tab-question-management .export-panel');
    var questionCard = document.querySelector('#tab-question-management #questionManageCard');

    if (!exportPanel && !questionCard) return false;

    var panel = createPanel();

    if (exportPanel) {
      // CSV管理パネルの末尾に追加することで、ダッシュボードのタブメニュー崩れを防ぐ。
      exportPanel.appendChild(panel);
      return true;
    }

    questionCard.appendChild(panel);
    return true;
  }

  function boot() {
    insertPanel();

    var tries = 0;
    var timer = setInterval(function () {
      tries += 1;
      if (insertPanel() || tries > 20) clearInterval(timer);
    }, 500);

    if ('MutationObserver' in window) {
      var observer = new MutationObserver(function () {
        insertPanel();
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
