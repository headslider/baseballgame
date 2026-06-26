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
  // client_token を取得（localStorage から）
  const clientToken = localStorage.getItem('baseballClientToken') || '';
  if (!clientToken) {
    alert('クライアントトークンが見つかりません。ログインし直してください。');
    return;
  }

  const formData = new FormData();
  formData.append('player_id', playerId);
  formData.append('client_token', clientToken);

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
    } else if (xhr.status === 401) {
      alert('クライアント認証に失敗しました。ログインし直してください。');
    } else if (xhr.status === 404) {
      alert('プレイヤーが見つかりません。');
    } else {
      alert('削除トークン取得に失敗しました（ステータス: ' + xhr.status + '）');
    }
  };
  xhr.onerror = function() {
    alert('削除トークン取得に失敗しました');
  };
  xhr.open('POST', 'api/user_delete_request.php');
  xhr.send(formData);
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
  title.textContent = 'アカウントを本当に削除しますか？';

  // 説明文
  const description = document.createElement('p');
  description.style.cssText = `
    margin: 0 0 16px 0;
    color: #6c757d;
    line-height: 1.6;
  `;
  const descPart1 = document.createElement('span');
  descPart1.textContent = 'プレイヤー登録情報、ランキング・解放情報、野球博士チャレンジの成績、間違い復習データ、Push通知登録、アクセス履歴を削除します。';
  descPart1.style.display = 'block';
  descPart1.style.marginBottom = '8px';
  descPart1.style.fontWeight = 'bold';

  const descPart2 = document.createElement('span');
  descPart2.textContent = '通常ゲームのプレイ履歴・スコアログは、ゲーム運営、不正利用対策およびサービス改善のため、削除後も一定期間保持される場合があります。';
  descPart2.style.display = 'block';
  descPart2.style.fontSize = '14px';

  description.appendChild(descPart1);
  description.appendChild(descPart2);

  // 削除対象データセクション
  const deletedSection = document.createElement('div');
  deletedSection.style.cssText = `
    margin: 0 0 16px 0;
    padding: 12px;
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    border-radius: 4px;
  `;

  const deletedTitle = document.createElement('p');
  deletedTitle.style.cssText = `
    margin: 0 0 8px 0;
    font-weight: bold;
    color: #212529;
  `;
  deletedTitle.textContent = '■この端末で使用しているプレイヤーIDに紐づく以下のデータを削除します。';
  deletedSection.appendChild(deletedTitle);

  // 削除対象データリスト
  const list = document.createElement('ul');
  list.style.cssText = `
    margin: 0 0 0 20px;
    color: #6c757d;
  `;
  const dataItems = [
    'プレイヤー登録情報',
    'ランキング・解放情報',
    '野球博士チャレンジの成績',
    '間違い復習データ',
    'Push通知の登録情報',
    'アクセス履歴'
  ];
  dataItems.forEach(item => {
    const li = document.createElement('li');
    li.style.marginBottom = '4px';
    li.textContent = item;
    list.appendChild(li);
  });
  deletedSection.appendChild(list);

  // 保持されるデータセクション
  const retainedSection = document.createElement('div');
  retainedSection.style.cssText = `
    margin: 0 0 16px 0;
    padding: 12px;
    background: #e7f3ff;
    border-left: 4px solid #0066cc;
    border-radius: 4px;
  `;

  const retainedTitle = document.createElement('p');
  retainedTitle.style.cssText = `
    margin: 0 0 8px 0;
    font-weight: bold;
    color: #212529;
  `;
  retainedTitle.textContent = '■保持されるデータ';
  retainedSection.appendChild(retainedTitle);

  const retainedMessage = document.createElement('p');
  retainedMessage.style.cssText = `
    margin: 0;
    color: #6c757d;
    line-height: 1.6;
    font-size: 14px;
  `;
  retainedMessage.textContent = '通常ゲームのプレイ履歴・スコアログは、ゲーム運営、不正利用対策およびサービス改善のため、削除後も一定期間保持される場合があります。';
  retainedSection.appendChild(retainedMessage);

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
  dialog.appendChild(description);
  dialog.appendChild(deletedSection);
  dialog.appendChild(retainedSection);
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
  xhr.onload = async function() {
    if (xhr.status === 200) {
      const response = JSON.parse(xhr.responseText);
      if (response.ok) {
        // Push通知登録を削除（サーバー側削除とクライアント側 unsubscribe を並行実行）
        try {
          if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
              await subscription.unsubscribe();
            }
          }
        } catch (e) {
          console.warn('Push通知登録の削除に失敗:', e);
        }

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
