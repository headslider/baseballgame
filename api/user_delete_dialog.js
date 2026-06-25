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
  const profileSection = document.getElementById('myPageProfile');
  if (!profileSection) return;

  // 既に追加されている場合はスキップ
  if (document.getElementById('userDeleteBtn')) return;

  const deleteBtn = document.createElement('button');
  deleteBtn.id = 'userDeleteBtn';
  deleteBtn.className = 'secondary user-delete-btn';
  deleteBtn.type = 'button';
  deleteBtn.textContent = '⚠️ アカウント削除';
  deleteBtn.style.marginTop = '12px';
  deleteBtn.style.color = '#ff6b6b';
  deleteBtn.style.borderColor = '#ff6b6b';
  deleteBtn.addEventListener('click', showUserDeleteDialog);

  profileSection.appendChild(deleteBtn);
}
