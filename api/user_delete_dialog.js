/**
 * プレイヤー削除用JavaScript（app.jsに統合予定）
 */

function showUserDeleteDialog() {
  const playerId = STATE.playerId || currentInputPlayerId?.();
  if (!playerId) {
    alert('プレイヤーIDが設定されていません');
    return;
  }

  const confirmed = confirm(
    '⚠️ アカウント削除の確認\n\n' +
    'このプレイヤーIDで記録されたすべてのデータ（スコア、ランキング、間違いプレイチェック）が削除されます。\n\n' +
    '削除後のデータ復旧はできません。本当に削除してよろしいですか？'
  );

  if (!confirmed) {
    return;
  }

  const doubleConfirmed = confirm(
    '最終確認です。本当に削除してもよろしいですか？\n\n' +
    'プレイヤーID: ' + playerId + '\n\n' +
    'この操作は取り消せません。'
  );

  if (!doubleConfirmed) {
    return;
  }

  // 削除要求送信
  submitUserDeleteRequest(playerId);
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
          'ご質問やご不明な点は、お問い合わせフォームよりご連絡ください。'
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

  // 設定内容の最後に削除ボタンを追加
  const hr = document.createElement('hr');
  hr.style.margin = '24px 0';

  const deleteSection = document.createElement('div');
  deleteSection.style.marginTop = '24px';

  const deleteTitle = document.createElement('h3');
  deleteTitle.textContent = 'アカウント削除';
  deleteTitle.style.fontSize = '16px';
  deleteTitle.style.color = '#212529';
  deleteTitle.style.marginBottom = '12px';

  const deleteDesc = document.createElement('p');
  deleteDesc.style.color = '#6c757d';
  deleteDesc.style.fontSize = '14px';
  deleteDesc.style.marginBottom = '12px';
  deleteDesc.textContent = 'アカウントとすべての関連データを削除します。この操作は取り消せません。';

  const deleteBtn = document.createElement('button');
  deleteBtn.id = 'userDeleteBtn';
  deleteBtn.className = 'secondary';
  deleteBtn.type = 'button';
  deleteBtn.textContent = '⚠️ アカウント削除';
  deleteBtn.style.color = '#ff6b6b';
  deleteBtn.style.borderColor = '#ff6b6b';
  deleteBtn.style.display = 'inline-block';
  deleteBtn.addEventListener('click', showUserDeleteDialog);

  deleteSection.appendChild(deleteTitle);
  deleteSection.appendChild(deleteDesc);
  deleteSection.appendChild(deleteBtn);

  const settingsContent = settingsCard.querySelector('.mypage-head ~ *') || settingsCard.lastChild;
  if (settingsContent) {
    settingsCard.insertBefore(hr, settingsContent.nextSibling);
    settingsCard.insertBefore(deleteSection, hr.nextSibling);
  } else {
    settingsCard.appendChild(hr);
    settingsCard.appendChild(deleteSection);
  }
}

// ページ読み込み時に削除ボタンを追加
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', addUserDeleteButton);
} else {
  addUserDeleteButton();
}
