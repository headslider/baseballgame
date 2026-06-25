/**
 * プレイヤー削除用JavaScript
 */

function showUserDeleteDialog() {
  const playerId = STATE.playerId || currentInputPlayerId?.();
  if (!playerId) {
    alert('プレイヤーIDが設定されていません');
    return;
  }

  // カスタム警告ダイアログを表示
  showDeleteConfirmationDialog(playerId);
}

function showDeleteConfirmationDialog(playerId) {
  // 既存のダイアログがあれば削除
  const existingDialog = document.getElementById('deleteConfirmDialog');
  if (existingDialog) {
    existingDialog.remove();
  }

  // ダイアログ背景（オーバーレイ）
  const overlay = document.createElement('div');
  overlay.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
  `;

  // ダイアログボックス
  const dialog = document.createElement('div');
  dialog.id = 'deleteConfirmDialog';
  dialog.style.cssText = `
    background: white;
    padding: 32px;
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
  `;

  // タイトル
  const title = document.createElement('h2');
  title.style.cssText = `
    margin: 0 0 16px 0;
    font-size: 20px;
    color: #dc3545;
    font-weight: bold;
  `;
  title.textContent = '⚠️ アカウントを本当に削除しますか？';

  // 警告メッセージ
  const message = document.createElement('p');
  message.style.cssText = `
    margin: 0 0 16px 0;
    color: #6c757d;
    line-height: 1.6;
  `;
  message.textContent = 'この操作は取り消すことができません。アカウントと関連するすべてのデータが永久に削除されます。';

  // 削除対象データリスト
  const list = document.createElement('ul');
  list.style.cssText = `
    margin: 0 0 24px 0;
    padding-left: 20px;
    color: #6c757d;
  `;
  const dataItems = [
    'プレイヤープロフィール',
    'ゲーム成績・スコア',
    'ランキング記録',
    ' 間違いプレイチェック履歴',
    '野球博士チャレンジの成績',
    'すべてのアカウント関連データ'
  ];
  dataItems.forEach(item => {
    const li = document.createElement('li');
    li.style.marginBottom = '8px';
    li.textContent = item;
    list.appendChild(li);
  });

  // プレイヤーID表示
  const idDisplay = document.createElement('div');
  idDisplay.style.cssText = `
    background: #f8f9fa;
    padding: 12px;
    border-radius: 6px;
    margin: 0 0 24px 0;
    border-left: 4px solid #dc3545;
  `;
  const idLabel = document.createElement('strong');
  idLabel.style.color = '#212529';
  idLabel.textContent = 'プレイヤーID: ';
  const idValue = document.createElement('span');
  idValue.style.color = '#dc3545';
  idValue.textContent = playerId;
  idDisplay.appendChild(idLabel);
  idDisplay.appendChild(idValue);

  // ボタン容器
  const buttonContainer = document.createElement('div');
  buttonContainer.style.cssText = `
    display: flex;
    gap: 12px;
    justify-content: flex-end;
  `;

  // キャンセルボタン
  const cancelBtn = document.createElement('button');
  cancelBtn.style.cssText = `
    padding: 10px 24px;
    border: 1px solid #6c757d;
    background: white;
    color: #6c757d;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
  `;
  cancelBtn.textContent = 'キャンセル';
  cancelBtn.onclick = function() {
    overlay.remove();
  };

  // 削除ボタン
  const deleteBtn = document.createElement('button');
  deleteBtn.style.cssText = `
    padding: 10px 24px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
  `;
  deleteBtn.textContent = 'アカウント削除';
  deleteBtn.onclick = function() {
    overlay.remove();
    submitUserDeleteRequest(playerId);
  };

  buttonContainer.appendChild(cancelBtn);
  buttonContainer.appendChild(deleteBtn);

  // ダイアログに要素を追加
  dialog.appendChild(title);
  dialog.appendChild(message);
  dialog.appendChild(list);
  dialog.appendChild(idDisplay);
  dialog.appendChild(buttonContainer);

  overlay.appendChild(dialog);
  document.body.appendChild(overlay);

  // キーボード操作対応
  document.addEventListener('keydown', function handleEsc(e) {
    if (e.key === 'Escape') {
      overlay.remove();
      document.removeEventListener('keydown', handleEsc);
    }
  });
}

function submitUserDeleteRequest(playerId) {
  const formData = new FormData();
  formData.append('player_id', playerId);
  formData.append('reason', '');
  formData.append('confirm', '1');

  const xhr = new XMLHttpRequest();
  xhr.onload = function() {
    if (xhr.status === 200) {
      const response = JSON.parse(xhr.responseText);
      if (response.ok) {
        alert(
          '削除要求を受け付けました。\n\n' +
          'リクエストID: ' + response.request_id + '\n\n' +
          '管理チームが3営業日以内に確認し、アカウントを削除いたします。'
        );
        // ログアウト
        logout();
      } else {
        alert('削除要求の送信に失敗しました: ' + response.error);
      }
    } else {
      alert('削除要求の送信に失敗しました（ステータス: ' + xhr.status + '）');
    }
  };
  xhr.onerror = function() {
    alert('削除要求の送信に失敗しました');
  };
  xhr.open('POST', 'api/user_delete_request.php');
  xhr.send(formData);
}

function addUserDeleteButton() {
  // 設定画面に削除ボタンを追加
  const settingsCard = document.querySelector('.settings-card');
  if (!settingsCard) return;

  // 既に追加されている場合はスキップ
  if (document.getElementById('userDeleteBtn')) return;

  // 設定セクションのスタイルに統一
  const deleteSection = document.createElement('div');
  deleteSection.className = 'settings-section';
  deleteSection.style.borderTop = '1px solid #e9ecef';
  deleteSection.style.paddingTop = '24px';
  deleteSection.style.marginTop = '24px';

  const deleteTitle = document.createElement('h3');
  deleteTitle.textContent = 'アカウント削除';
  deleteTitle.style.marginBottom = '12px';

  const deleteNote = document.createElement('p');
  deleteNote.className = 'settings-note';
  deleteNote.textContent = 'アカウントとそれに紐づくすべてのデータを削除します。この操作は取り消すことができません。';

  const deleteBtn = document.createElement('button');
  deleteBtn.id = 'userDeleteBtn';
  deleteBtn.className = 'secondary';
  deleteBtn.type = 'button';
  deleteBtn.textContent = '⚠️ アカウント削除';
  deleteBtn.style.color = '#dc3545';
  deleteBtn.style.borderColor = '#dc3545';
  deleteBtn.addEventListener('click', showUserDeleteDialog);

  deleteSection.appendChild(deleteTitle);
  deleteSection.appendChild(deleteNote);
  deleteSection.appendChild(deleteBtn);

  // 一番下に追加
  settingsCard.appendChild(deleteSection);
}

// ページ読み込み時に削除ボタンを追加
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', addUserDeleteButton);
} else {
  addUserDeleteButton();
}
