/*
 * admin_release_version_fix_ui.js v830
 * 管理画面「バージョン管理」の保存・削除を専用APIへ接続する修正。
 * admin_api.php本体を大きく変更せず、release_version_save / release_version_delete 未実装問題を補完します。
 */
(function () {
  'use strict';

  var RELEASE_API = 'api/admin_release_versions_api.php';

  function getAdminToken() {
    try {
      return sessionStorage.getItem('superAdminToken') || '';
    } catch (e) {
      return '';
    }
  }

  async function releaseCall(action, payload) {
    var body = Object.assign({}, payload || {}, {
      action: action,
      token: getAdminToken()
    });
    var res = await fetch(RELEASE_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    var json = await res.json().catch(function () {
      return { ok: false, message: '通信結果を読めませんでした' };
    });
    if (!res.ok || !json.ok) {
      throw new Error(json.message || json.error || '操作に失敗しました');
    }
    return json;
  }

  async function fixedLoadReleaseVersions() {
    try {
      var data = await releaseCall('release_versions', {});
      latestReleaseVersions = data;
      renderReleaseVersions(data);
      resetReleaseVersionForm(false);
    } catch (e) {
      setHtml('releaseVersionCurrent', '<p class="err">' + esc(e.message) + '</p>');
    }
  }

  async function fixedSaveReleaseVersion() {
    var payload = {
      id: document.getElementById('releaseVersionId') ? document.getElementById('releaseVersionId').value : '',
      public_version: document.getElementById('releasePublicVersion').value,
      release_type: document.getElementById('releaseType').value,
      released_at: document.getElementById('releaseDate').value,
      title: document.getElementById('releaseTitle').value,
      internal_version: document.getElementById('releaseInternalVersion').value,
      cache_version: document.getElementById('releaseCacheVersion').value,
      public_summary: document.getElementById('releasePublicSummary').value,
      admin_note: document.getElementById('releaseAdminNote').value,
      visible: document.getElementById('releaseVisible').checked,
      is_current: document.getElementById('releaseCurrent').checked
    };
    setText('releaseVersionMsg', '保存中...');
    try {
      var data = await releaseCall('release_version_save', payload);
      latestReleaseVersions = data;
      renderReleaseVersions(data);
      resetReleaseVersionForm(false);
      setText('releaseVersionMsg', data.message || '保存しました。');
    } catch (e) {
      setText('releaseVersionMsg', e.message);
    }
  }

  async function fixedDeleteReleaseVersion(id) {
    if (!id || !confirm('このバージョン履歴を削除しますか？')) return;
    try {
      var data = await releaseCall('release_version_delete', { id: id });
      latestReleaseVersions = data;
      renderReleaseVersions(data);
      resetReleaseVersionForm(false);
      setText('releaseVersionMsg', data.message || '削除しました。');
    } catch (e) {
      alert(e.message);
    }
  }

  function install() {
    try {
      window.loadReleaseVersions = fixedLoadReleaseVersions;
      window.saveReleaseVersion = fixedSaveReleaseVersion;
      window.deleteReleaseVersion = fixedDeleteReleaseVersion;

      // 既にバージョン管理タブを表示中の場合は再読込する
      var panel = document.getElementById('tab-version-management');
      if (panel && panel.classList.contains('active')) {
        fixedLoadReleaseVersions();
      }
    } catch (e) {
      console.error('release version fix ui install failed', e);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
  } else {
    install();
  }
}());
