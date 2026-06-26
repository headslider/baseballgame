/**
 * プレイヤー削除用JavaScript
 */

function showUserDeleteDialog() {
  const playerId = STATE.playerId || currentInputPlayerId?.();
  if (!playerId) {
    alert('プレイヤーIDが設定されていません');
    return;
  }

  // ステップ1：サーバーからトークンを取得
  getDeleteToken(playerId);
}

function getDeleteToken(playerId) {
  const xhr = new XMLHttpRequest();
  xhr.onload = function() {
    if (xhr.status === 200) {
      const response = JSON.parse(xhr.responseText);
      if (response.ok && response.token) {
        // トークンを localStorage に保存
        localStorage.setItem(`delete_token_${playerId}`, response.token);
        localStorage.setItem(`delete_token_expires_${playerId}`, response.token_expires_at);

        // ステップ2：削除確認ダイアログを表示
        showDeleteConfirmationDialog(playerId);
      } else {
        alert('削除トークン取得に失敗しました: ' + (response.error || '不明なエラー'));
      }
    } else {
      alert('削除トークン取得に失敗しました（ステータス: ' + xhr.status + '）');
    }
  };
  xhr.onerror = function() {
    alert('削除トークン取得に失敗しました');
  };
  xhr.open('POST', 'api/user_delete_request.php');
  xhr.send(); // token パラメータなし → トークン生成フェーズ
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
    font-weight: bold;
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
    'プレイヤー登録情報',
    'ゲーム成績・スコア',
    'スコアログ記録',
    '間違いプレイチェック履歴',
    '野球博士チャレンジの成績',
    '機能解放状態'
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
  // localStorage からトークンを取得
  const deleteToken = localStorage.getItem(`delete_token_${playerId}`);
  const tokenExpires = localStorage.getItem(`delete_token_expires_${playerId}`);

  if (!deleteToken) {
    alert('削除トークンが見つかりません。削除申請をやり直してください。');
    return;
  }

  // トークン有効期限確認
  if (tokenExpires && new Date(tokenExpires) < new Date()) {
    alert('削除トークンが期限切れです。削除申請をやり直してください。');
    localStorage.removeItem(`delete_token_${playerId}`);
    localStorage.removeItem(`delete_token_expires_${playerId}`);
    location.reload();
    return;
  }

  const formData = new FormData();
  formData.append('player_id', playerId);
  formData.append('token', deleteToken); // トークン送信（必須）
  formData.append('reason', '');

  const xhr = new XMLHttpRequest();
  xhr.onload = function() {
    if (xhr.status === 200) {
      const response = JSON.parse(xhr.responseText);
      if (response.ok) {
        alert(
          'アカウントが削除されました。\n\n' +
          '削除日時: ' + response.deleted_at + '\n\n' +
          'システムからログアウトします。'
        );
        // トークンをクリア
        localStorage.removeItem(`delete_token_${playerId}`);
        localStorage.removeItem(`delete_token_expires_${playerId}`);
        // ログアウト
        logoutPlayer();
      } else {
        alert('削除に失敗しました: ' + response.error);
      }
    } else {
      alert('削除に失敗しました（ステータス: ' + xhr.status + '）');
    }
  };
  xhr.onerror = function() {
    alert('削除に失敗しました');
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

  const deleteTitle = document.createElement('h3');
  deleteTitle.textContent = 'アカウント削除';

  const deleteNote = document.createElement('p');
  deleteNote.className = 'settings-note';
  deleteNote.textContent = 'アカウントとそれに紐づくすべてのデータを削除します。この操作は取り消すことができません。';

  const deleteBtn = document.createElement('button');
  deleteBtn.id = 'userDeleteBtn';
  deleteBtn.className = 'secondary';
  deleteBtn.type = 'button';
  deleteBtn.textContent = '⚠️ アカウント削除';
  deleteBtn.style.marginTop = '12px';
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
