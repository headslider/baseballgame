
const STATE={questions:[],config:null,sequence:[],current:0,score:0,attackScore:0,defenseScore:0,logs:[],playerId:"",grade:3,position:"SS",loggedIn:false,progress:{},timer:null,questionStartedAt:0,questionAnswered:false,adminMode:false,adminQuestionTestMode:false,adminQuestionTestInfo:null,mistakeReviewEnabled:false,featureFlags:{},featureStatus:null};
const ADMIN_QUESTION_TEST_POSITIONS=["P","C","1B","2B","3B","SS","LF","CF","RF"];
function normalizeAdminQuestionTestId(v){
  return String(v||"").trim().toUpperCase().replace(/[^A-Z0-9_-]/g,"").slice(0,24);
}
function normalizeAdminQuestionTestPosition(v){
  const pos=String(v||"").trim().toUpperCase();
  return ADMIN_QUESTION_TEST_POSITIONS.includes(pos)?pos:"";
}
function readAdminQuestionTestRequest(){
  const params=new URLSearchParams(location.search||"");
  const questionId=normalizeAdminQuestionTestId(params.get("admin_test_question_id"));
  if(params.get("admin_test")!=="1"||!questionId)return null;
  return {
    questionId,
    position:normalizeAdminQuestionTestPosition(params.get("admin_test_position")),
    nonce:String(params.get("admin_test_nonce")||"").trim()
  };
}
function consumeAdminQuestionTestPermit(req){
  if(!req||!req.questionId||!req.nonce)return false;
  try{
    const raw=localStorage.getItem("baseballAdminQuestionTestPermit")||"";
    const permit=raw?JSON.parse(raw):null;
    localStorage.removeItem("baseballAdminQuestionTestPermit");
    if(!permit||typeof permit!=="object")return false;
    if(Date.now()-Number(permit.issuedAt||0)>5*60*1000)return false;
    if(String(permit.nonce||"")!==req.nonce)return false;
    if(normalizeAdminQuestionTestId(permit.questionId)!==req.questionId)return false;
    const permitPos=normalizeAdminQuestionTestPosition(permit.position);
    if((permitPos||"")!==(req.position||""))return false;
    return true;
  }catch(e){
    return false;
  }
}
function isAdminQuestionTestMode(){
  return !!(STATE.adminQuestionTestMode || window.ADMIN_QUESTION_TEST_MODE);
}
const GRADE_STEPS=[3,4,5,6];
const GRADE_CLEAR_SCORE=40;
const GRADE_TIME_LIMITS={3:30,4:25,5:20,6:15};
const OPTION_FEATURES={
  mistake_review:{label:"間違いプレイチェック機能",restricted:true},
  device_transfer:{label:"端末引継ぎ機能",restricted:true},
  quiz_master:{label:"野球博士チャレンジ",restricted:true},
  admin_mode:{label:"管理者用モード",restricted:true}
};
const $=id=>document.getElementById(id);
const QUIZ_MASTER_DAILY_LIMIT=5;
const QUIZ_MASTER_DAILY_LIMIT_ENABLED=false;
const QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true;
const QUIZ_MASTER_TOTAL_QUESTIONS=20;
const QUIZ_MASTER_CHECKPOINT_LEVEL=14;
const QUIZ_MASTER_CHALLENGE_START_LEVEL=15;
const QUIZ_MASTER_CHALLENGE_MULTIPLIER_STEP=.2;
const QUIZ_MASTER_TIME_LIMITS={1:20,2:20,3:20,4:20,5:20,6:17,7:17,8:15,9:15,10:13,11:13,12:13,13:13,14:13,15:13,16:13,17:13,18:13,19:13,20:13};
const QUIZ_MASTER_TITLE_AWARD_ALWAYS_FOR_TEST=false;
const QUIZ_MASTER_TITLE_DEFAULTS=[
  {level:1,title:"ボールボーイ",point:0},{level:2,title:"バットボーイ",point:10000},{level:3,title:"ベンチ入り",point:40000},{level:4,title:"代打の切り札",point:130000},{level:5,title:"スタメン",point:290000},
  {level:6,title:"クリーンアップ",point:500000},{level:7,title:"四番打者",point:770000},{level:8,title:"エース",point:1110000},{level:9,title:"キャプテン",point:1510000},{level:10,title:"ベストナイン",point:1970000},
  {level:11,title:"オールスター",point:2500000},{level:12,title:"甲子園スター",point:3080000},{level:13,title:"ドラフト候補",point:3730000},{level:14,title:"プロ野球選手",point:4440000},{level:15,title:"メジャーリーガー",point:5210000},
  {level:16,title:"首位打者",point:6040000},{level:17,title:"ホームラン王",point:6930000},{level:18,title:"サイヤング賞",point:7890000},{level:19,title:"MVP",point:8910000},{level:20,title:"野球殿堂",point:10000000}
];
const QUIZ_MASTER_STATE={questions:[],questionStats:{},titles:[],sequence:[],currentIndex:0,score:0,selected:null,timer:null,remaining:20,answered:false,startedAt:0,questionStartedAt:0,logs:[],challenge:false,guestTest:false,animating:false,roundToken:0,endReason:"",fiftyUsed:false,fiftyHidden:null,failureReview:null,fiftyPromptOpen:false,choiceOrder:[]};
const INNING_SLOTS=[
  ["1回表",0,"1B","attack"],["1回表",1,"2B","attack"],["1回表",2,"3B","attack"],
  ["1回裏",0,"1B","defense"],["1回裏",1,"2B","defense"],["1回裏",2,"3B","defense"],
  ["2回表",0,"1B","attack"],["2回表",1,"2B","attack"],["2回表",2,"3B","attack"],
  ["2回裏",0,"1B","defense"],["2回裏",1,"2B","defense"],["2回裏",2,"3B","defense"],
  ["3回表",0,"1B","attack"],["3回表",1,"2B","attack"],["3回表",2,"3B","attack"],
  ["3回裏",0,"1B","defense"],["3回裏",1,"2B","defense"],["3回裏",2,"3B","defense"]
];
const EXTRA_ATTACK_SLOTS=[
  [0,"2B"], // ノーアウト二塁
  [0,"3B"], // ノーアウト三塁
  [1,"3B"]  // 1アウト三塁
];
const EXTRA_DEFENSE_OUTFIELD_SLOTS=[];
function isOutfieldIrrelevantQuestion(q,pos){
  if(!q || q.type!=="defense" || !["LF","CF","RF"].includes(pos))return false;
  const v=q.visual||{};
  const story=`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${v.ball_path||""} ${v.ball_holder||""} ${v.target_position||""}`;
  if(/クリーンナップ|強打者|守備位置指示|外野準備|outfield|cleanup|positioning|shift/.test(story))return false;
  return /ワイルドピッチ|暴投|捕逸|パスボール|ワンバウンド|振り逃げ|三振後|dropped_third|牽制|偽投|ボーク|pickoff|バント|スクイズ|bunt|squeeze|キャッチャーフライ|捕手フライ|本塁付近の上|catcher_fly|キャッチャーゴロ|捕手ゴロ|catcher_grounder|ピッチャー前|投手前|ピッチャーゴロ|投手ゴロ|pitcher_grounder|unknown_to_pitcher|挟殺|ランダウン|run_down|rundown|満塁のピッチャーゴロ|一二塁間フライ|三遊間.*フライ|内野小フライ|中間フライ|盗塁送球|捕手送球|本塁カバー|投球動作|3番打者への入り方|4番打者との勝負|5番打者と三塁走者|下位打線の次が上位/.test(story);
}
function isCatcherFlyOnlyQuestion(q){
  if(!q || q.type!=="defense")return false;
  const v=q.visual||{};
  const story=`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${v.ball_path||""}`;
  return /キャッチャーフライ|捕手フライ|本塁付近の上|catcher_fly/.test(story);
}
function questionAllowedForPosition(q,pos){
  if(!q || q.type!=="defense")return true;
  if(isCatcherFlyOnlyQuestion(q) && !["P","C"].includes(pos))return false;
  if(isOutfieldIrrelevantQuestion(q,pos))return false;
  if(Array.isArray(q.positions) && q.positions.length && !q.positions.includes(pos))return false;
  if(q.choices_by_position && !q.choices_by_position[pos])return false;
  return true;
}
function questionAllowedForGrade(q,grade){
  const g=Number(grade);
  if(Number(q.grade)>g+1)return false;
  if(q.max_grade!==null && q.max_grade!==undefined && q.max_grade!=="" && g>Number(q.max_grade))return false;
  if(q.min_grade!==null && q.min_grade!==undefined && q.min_grade!=="" && g<Number(q.min_grade))return false;
  return true;
}
function hasNumericOuts(q){
  if(!q)return false;
  const qo=q.outs;
  if(qo===null || qo===undefined || qo==="")return false;
  const n=Number(qo);
  return Number.isInteger(n)&&n>=0&&n<=2;
}
function hasCommonOutsScope(q){
  return !!(q&&String(q.outs_scope||"").toLowerCase()==="common");
}
function slotOutsForQuestion(q){
  // v802: outs未設定を自動的に全アウト対応にしない。
  // 数値outsがある問題は該当アウトのみ、outs_scope:"common" の問題だけ0/1/2すべてで使用する。
  // outs未設定かつouts_scopeなしは未監査として出題スロット候補から除外する。
  if(hasNumericOuts(q))return [Number(q.outs)];
  if(hasCommonOutsScope(q))return [0,1,2];
  return [];
}
function availableSlotsForType(typ,grade,pos){
  const seen=new Set();
  const out=[];
  STATE.questions.forEach(q=>{
    if(!q || q.type!==typ)return;
    if(!questionAllowedForGrade(q,grade))return;
    if(typ==="defense" && !questionAllowedForPosition(q,pos))return;
    const stage=q.stage||"";
    slotOutsForQuestion(q).forEach(outs=>{
      const key=`${outs}|${stage}`;
      if(!seen.has(key)){
        seen.add(key);
        out.push([outs,stage]);
      }
    });
  });
  return out;
}

function questionPoolForSlot(typ,stage,outs,grade,pos,usedQuestionIds,options){
  const basePool=STATE.questions.filter(q=>q.type===typ&&q.stage===stage&&questionAllowedForGrade(q,grade)&&(typ!=="defense"||questionAllowedForPosition(q,pos)));
  const exact=basePool.filter(q=>hasNumericOuts(q)&&String(q.outs)===String(outs));
  const common=basePool.filter(q=>hasCommonOutsScope(q));
  const poolBase=exact.length?exact.concat(common):common;
  const unused=usedQuestionIds?poolBase.filter(q=>!q.id||!usedQuestionIds.has(q.id)):[];
  const strictUnused=!!(options&&options.strictUnused);
  if(usedQuestionIds&&unused.length)return unused;
  if(usedQuestionIds&&strictUnused)return [];
  return poolBase;
}
function candidateCountForSlot(typ,outs,stage,grade,pos,usedQuestionIds){
  // v802: 動的slot選択時は「使用済みIDしか残っていないslot」を候補数ありとして扱わない。
  // これにより、outs_scope:common の少数問題が同一ゲーム内で再利用される事故を抑止する。
  return questionPoolForSlot(typ,stage,outs,grade,pos,usedQuestionIds,{strictUnused:true}).length;
}
function weightedPick(items,weightFn){
  if(!items||!items.length)return null;
  const weights=items.map(x=>Math.max(0.001,Number(weightFn(x))||0.001));
  const total=weights.reduce((a,b)=>a+b,0);
  let r=Math.random()*total;
  for(let i=0;i<items.length;i++){
    r-=weights[i];
    if(r<=0)return items[i];
  }
  return items[items.length-1];
}
function slotWeight(typ,outs,stage,grade,pos,usedQuestionIds){
  const count=candidateCountForSlot(typ,outs,stage,grade,pos,usedQuestionIds);
  if(count<=0)return 0.001;
  let w=Math.sqrt(count);
  if(count===1)w*=0.28;
  else if(count===2)w*=0.45;
  else if(count===3)w*=0.65;
  else if(count<=5)w*=0.82;
  return w;
}
function recentQuestionKey(pid){
  return `baseballRecentQuestionIds:${pid||STATE.playerId||"guest"}`;
}
function loadRecentQuestionIds(pid){
  try{
    const raw=localStorage.getItem(recentQuestionKey(pid));
    const arr=raw?JSON.parse(raw):[];
    return Array.isArray(arr)?arr.filter(Boolean).slice(-80):[];
  }catch(e){
    return [];
  }
}
function saveRecentQuestionIds(ids,pid){
  try{
    localStorage.setItem(recentQuestionKey(pid),JSON.stringify((ids||[]).filter(Boolean).slice(-80)));
  }catch(e){}
}
function questionHistoryWeight(q,recentIds,usedQuestionIds){
  if(!q||!q.id)return 1;
  if(usedQuestionIds&&usedQuestionIds.has(q.id))return 0.05;
  const idx=recentIds.lastIndexOf(q.id);
  if(idx<0)return 1;
  const age=recentIds.length-1-idx;
  if(age<10)return 0.08;
  if(age<25)return 0.20;
  if(age<50)return 0.45;
  return 0.70;
}
function chooseQuestionFromPool(pool,typ,usedThemesBySide,attackUsedKeys,recentIds,usedQuestionIds){
  const lastThemes=usedThemesBySide[typ].slice(-2);
  let bestPool=pool.filter(q=>!lastThemes.includes(q.theme)&&(!attackUsedKeys||!attackUsedKeys.has(attackSimilarKey(q))));
  if(!bestPool.length&&attackUsedKeys)bestPool=pool.filter(q=>!attackUsedKeys.has(attackSimilarKey(q)));
  if(!bestPool.length)bestPool=pool.filter(q=>!lastThemes.includes(q.theme));
  if(!bestPool.length)bestPool=pool;
  return weightedPick(bestPool,q=>questionHistoryWeight(q,recentIds,usedQuestionIds))||bestPool[0]||pool[0];
}

function pickDynamicSlot(typ,baseSlot,index,usedSlotKeys,usedQuestionIds){
  const inning=baseSlot[0];
  const grade=Number($("grade").value);
  const pos=STATE.grade<=2?"BASIC":STATE.position;
  let available=availableSlotsForType(typ,grade,pos).filter(([outs,stage])=>candidateCountForSlot(typ,outs,stage,grade,pos,usedQuestionIds)>0);
  if(!available.length){
    // 全slotで未使用IDが尽きた場合だけ、最後の救済として使用済みも含めた候補へ戻す。
    // 通常はここに来ない。来た場合でもエラー停止ではなくゲーム継続を優先する。
    available=availableSlotsForType(typ,grade,pos).filter(([outs,stage])=>questionPoolForSlot(typ,stage,outs,grade,pos,null).length>0);
  }
  const hasSlot=([outs,stage])=>available.some(([o,s])=>String(o)===String(outs)&&String(s)===String(stage));
  let forced=null;
  if(typ==="attack"){
    const must=EXTRA_ATTACK_SLOTS[index];
    if(must && hasSlot(must))forced=must;
  }else if(typ==="defense" && ["LF","CF","RF"].includes(pos)){
    const must=EXTRA_DEFENSE_OUTFIELD_SLOTS[index];
    if(must && hasSlot(must))forced=must;
  }
  if(forced){
    usedSlotKeys.add(`${typ}|${forced[0]}|${forced[1]}`);
    return [inning,forced[0],forced[1],typ];
  }
  const notUsed=available.filter(([outs,stage])=>!usedSlotKeys.has(`${typ}|${outs}|${stage}`));
  const fallback=available.filter(([outs,stage])=>String(outs)===String(baseSlot[1])&&String(stage)===String(baseSlot[2]));
  const pool=notUsed.length?notUsed:(fallback.length?fallback:available);
  const pick=weightedPick(pool,([outs,stage])=>slotWeight(typ,outs,stage,grade,pos,usedQuestionIds))||pool[0]||[baseSlot[1],baseSlot[2]];
  usedSlotKeys.add(`${typ}|${pick[0]}|${pick[1]}`);
  return [inning,pick[0],pick[1],typ];
}
function maxScoreForCurrentGame(){return (STATE.sequence&&STATE.sequence.length?STATE.sequence.length:18)*3}
function show(id){
  if(id!=="screen-game")clearQuestionTimer();
  if(id!=="screen-quiz-master")clearQuizMasterTimer();
  console.log(`[show] Transitioning to ${id}`);
  document.querySelectorAll(".screen").forEach(s=>s.classList.remove("active"));
  const target=$(id);
  if(target){
    target.classList.add("active");
    console.log(`[show] Added active class to ${id}`, target);
  }else{
    console.warn(`[show] Target element not found: ${id}`);
  }

  // v679: トップ背景がゲーム中に見えないよう、現在画面のbodyクラスを明示する。
  document.body.classList.toggle("screen-title-active",id==="screen-title");
  document.body.classList.toggle("screen-game-active",id==="screen-game");
  document.body.classList.toggle("screen-result-active",id==="screen-result");
  document.body.classList.toggle("screen-ranking-active",id==="screen-ranking");
  document.body.classList.toggle("screen-mypage-active",id==="screen-mypage");
  document.body.classList.toggle("screen-settings-active",id==="screen-settings");
  document.body.classList.toggle("screen-how-active",id==="screen-how");
  document.body.classList.toggle("screen-notices-active",id==="screen-notices");
  document.body.classList.toggle("screen-quiz-master-active",id==="screen-quiz-master"||id==="screen-quiz-master-result"||id==="screen-quiz-master-ranking"||id==="screen-quiz-master-menu"||id==="screen-quiz-master-ranks");
  // v798: iPhone/iPadなどでは、ID取得導線はPWAのトップ画面だけに表示する。
  // トップ以外の画面へ移動した時や、Safari等の通常ブラウザ表示では非表示に戻す。
  if(typeof updateIssueKeyActions==="function")updateIssueKeyActions();
}
function interruptGame(){
  if(!STATE.sequence||!STATE.sequence.length){
    show("screen-title");
    return;
  }
  if(confirm("ゲームを中断してトップに戻りますか？\n現在のプレイ結果は保存されません。")){
    clearQuestionTimer();
    STATE.sequence=[];
    STATE.current=0;
    STATE.questionAnswered=false;
    show("screen-title");
    trackAccessEvent("game_interrupt","back_to_title");
  }
}
function sanitizeId(v){return (v||"").replace(/[^\wぁ-んァ-ヶ一-龠ー-]/g,"").slice(0,24)}
function shuffle(a){const x=[...a];for(let i=x.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[x[i],x[j]]=[x[j],x[i]]}return x}
function stageLabel(s){return s==="BASIC"?"基本動作":s==="none"?"走者なし":s==="BR"?"打者走者":s==="1B"?"走者：一塁":s==="2B"?"走者：二塁":s==="3B"?"走者：三塁":s==="1B2B"?"走者：一・二塁":s==="1B3B"?"走者：一・三塁":s==="2B3B"?"走者：二・三塁":s==="1B2B3B"?"満塁":""}
function outsLabel(n){return Number(n)===0?"ノーアウト":`${n}アウト`}

function timeLimitForCurrentGrade(){
  if(STATE.adminMode||isAdminQuestionTestMode())return 0;
  return GRADE_TIME_LIMITS[Number(STATE.grade)]||0;
}
function gradeMenuLabel(g, locked=false){
  const n=Number(g);
  const base=n<=2?"3年生以下":`${n}年生`;
  const time=(STATE.adminMode||isAdminQuestionTestMode())?"制限時間なし":((GRADE_TIME_LIMITS[n]||0)>0?`制限時間${GRADE_TIME_LIMITS[n]}秒`:"制限時間なし");
  return `${base}（${time}）${locked?"（ロック中）":""}`;
}
function setTimerProgress({active=false,remainingSec=0,totalSec=0,danger=false,labelText=""}={}){
  const wrap=$("timeProgressWrap");
  const bar=$("timeProgressBar");
  const label=$("timeProgressLabel");
  if(wrap){
    wrap.classList.toggle("danger",!!danger&&!!active);
    wrap.classList.toggle("no-limit",!active);
  }
  if(bar){
    const ratio=active&&totalSec>0?Math.max(0,Math.min(1,remainingSec/totalSec)):1;
    bar.style.width=`${ratio*100}%`;
  }
  if(label){
    label.textContent=labelText || (active?`${remainingSec.toFixed(1)}秒`:"制限時間なし");
  }
}
function clearQuestionTimer(){
  if(STATE.timer){
    clearInterval(STATE.timer);
    STATE.timer=null;
  }
  const box=$("timerBox");
  if(box){
    box.hidden=true;
    box.textContent="";
    box.classList.remove("danger");
  }
  setTimerProgress({active:false,labelText:"制限時間なし"});
  const overlay=$("countdownOverlay");
  if(overlay){
    overlay.classList.remove("show");
    overlay.textContent="";
  }
}
function updateTimerDisplay(remaining,total){
  const rounded=Math.max(0,Math.round(Number(remaining||0)*10)/10);
  const danger=rounded<=3;
  const box=$("timerBox");
  if(box){
    box.hidden=false;
    box.textContent=`残り ${rounded.toFixed(1)}秒`;
    box.classList.toggle("danger",danger);
  }
  setTimerProgress({active:true,remainingSec:rounded,totalSec:Number(total||0),danger,labelText:`残り ${rounded.toFixed(1)}秒`});
  const overlay=$("countdownOverlay");
  if(overlay){
    const shown=Math.ceil(rounded);
    if(rounded>0&&rounded<=3){
      overlay.textContent=String(shown);
      overlay.classList.add("show");
    }else{
      overlay.classList.remove("show");
      overlay.textContent="";
    }
  }
}
function startQuestionTimer(q){
  clearQuestionTimer();
  STATE.questionAnswered=false;
  STATE.questionStartedAt=Date.now();
  const limit=timeLimitForCurrentGrade();
  if(!limit||q.type==="basic")return;
  updateTimerDisplay(limit,limit);
  STATE.timer=setInterval(()=>{
    const elapsed=(Date.now()-STATE.questionStartedAt)/1000;
    const remaining=Math.max(0,limit-elapsed);
    updateTimerDisplay(remaining,limit);
    if(remaining<=0){
      clearQuestionTimer();
      handleTimeout(q);
    }
  },100);
}
async function handleTimeout(q){
  if(STATE.questionAnswered)return;
  STATE.questionAnswered=true;
  disableChoices();
  STATE.logs.push({
    inning:q.inning,
    outs:q.outs,
    stage:q.stage,
    id:q.id,
    type:q.type,
    theme:q.theme,
    selected:"時間切れ",
    score:0,
    explain:"制限時間内に答えられなかったため0点です。",
    answer_time_ms:timeLimitForCurrentGrade()*1000,
    timeout:true
  });
  STATE.current++;
  if(STATE.current>=STATE.sequence.length)finishGame();
  else{
    const next=STATE.sequence[STATE.current];
    const prev=STATE.sequence[STATE.current-1];
    const title=sideStartTitle(next, prev);
    if(title)showTransitionTitle(title, renderQuestion);
    else renderQuestion();
  }
}
function responseTimeMs(){
  if(!STATE.questionStartedAt)return 0;
  return Math.max(0,Date.now()-STATE.questionStartedAt);
}



function featureStorageKey(pid){
  return `baseballFeatureFlags:${pid||STATE.playerId||"guest"}`;
}
function saveLocalFeatureFlags(pid,flags){
  try{localStorage.setItem(featureStorageKey(pid),JSON.stringify(flags||{}));}catch(e){}
}
function loadLocalFeatureFlags(pid){
  try{
    const raw=localStorage.getItem(featureStorageKey(pid));
    const obj=raw?JSON.parse(raw):{};
    return obj&&typeof obj==="object"?obj:{};
  }catch(e){return {}}
}
function isOptionFeatureRestricted(key){
  return !!(OPTION_FEATURES[key]&&OPTION_FEATURES[key].restricted);
}
function isFeatureUnlocked(key){
  return !!(STATE.featureFlags&&STATE.featureFlags[key]);
}
function featureLabel(key){
  return (OPTION_FEATURES[key]&&OPTION_FEATURES[key].label)||key;
}
function optionFeaturePayload(){
  return {player_id:STATE.playerId,client_token:getClientToken()};
}
async function refreshFeatureFlags(pid=STATE.playerId){
  if(!pid){
    STATE.featureFlags={};
    STATE.featureStatus=null;
    STATE.adminMode=false;
    localStorage.setItem("adminMode","0");
    updateAdminModeUI();
    renderFeatureUnlockSection();
    return null;
  }
  // ネットワーク確認中もキャッシュ済みフラグを保持し、解放ボタンが消えないようにする。
  STATE.featureFlags=loadLocalFeatureFlags(pid);
  STATE.featureStatus=null;
  try{
    const res=await fetch("api/get_features.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({player_id:pid,client_token:getClientToken()})
    });
    const json=await res.json().catch(()=>({ok:false}));
    if(res.ok&&json&&json.ok){
      STATE.featureFlags=json.flags||{};
      STATE.featureStatus=json;
      saveLocalFeatureFlags(pid,STATE.featureFlags);
    }
  }catch(e){
    console.warn("feature flag load skipped",e);
  }
  syncAdminModeFromFeature();
  if(isFeatureUnlocked("mistake_review")){
    enableMistakeReviewDefaultOnFirstUnlock(pid);
  }else{
    STATE.mistakeReviewEnabled=false;
    localStorage.setItem("mistakeReviewEnabled","0");
  }
  updateAdminModeUI();
  renderFeatureUnlockSection();
  syncMistakeReviewToggleUI();
  updateLoginUI();
  updateIssueKeyActions();
    return STATE.featureFlags;
}
async function redeemInviteId(){
  if(!STATE.playerId){
    alert("先にプレイヤーIDでログインしてください。");
    return;
  }
  const input=$("inviteIdInput");
  const code=String(input&&input.value||"").trim().toUpperCase();
  if(!code){
    alert("招待IDまたは管理者IDを入力してください。");
    return;
  }
  const msg=$("inviteIdMessage");
  if(msg)msg.textContent="プレイヤーIDを確認中...";
  const verified=await ensureCurrentPlayerVerifiedForFeature();
  if(!verified.ok){
    const text=verified.message||"プレイヤーIDの確認に失敗しました。";
    if(msg)msg.textContent=text;
    alert(text);
    return;
  }
  if(msg)msg.textContent="照合中...";
  try{
    const res=await fetch("api/redeem_invite.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({player_id:STATE.playerId,client_token:getClientToken(),invite_id:code})
    });
    const json=await res.json().catch(()=>({ok:false,error:"invalid response"}));
    if(!res.ok||!json.ok){
      const text=(json&&json.message)||"招待IDまたは管理者IDを確認できませんでした。";
      if(msg)msg.textContent=text;
      alert(text);
      return;
    }
    STATE.featureFlags=json.flags||{};
    STATE.featureStatus=json;
    saveLocalFeatureFlags(STATE.playerId,STATE.featureFlags);
    if(STATE.featureFlags.mistake_review){
      saveMistakeReviewSetting(true,STATE.playerId);
      localStorage.setItem("mistakeReviewEnabled","1");
    }
    if(input)input.value="";
    syncAdminModeFromFeature();
    updateAdminModeUI();
    renderFeatureUnlockSection();
    renderMistakeReviewSection();
    const names=(json.unlocked_features||[]).map(featureLabel).join("、")||"オプション機能";
    const idLabel=json.id_type==="admin"?"管理者ID":"招待ID";
    if(msg)msg.textContent=`${idLabel}で解放しました：${names}`;
    alert(`${idLabel}を登録しました。${names}が使えるようになりました。`);
  }catch(e){
    console.warn("invite redeem failed",e);
    if(msg)msg.textContent="通信エラーで照合できませんでした。";
    alert("通信エラーで招待IDまたは管理者IDを照合できませんでした。");
  }
}

function renderFeatureUnlockSection(){
  const box=$("myPageFeatureUnlock");
  if(!box)return;
  if(!STATE.playerId){
    box.innerHTML="";
    return;
  }
  const flags=STATE.featureFlags||{};
  const unlocked=Object.keys(OPTION_FEATURES).filter(k=>flags[k]).map(featureLabel);
  if(unlocked.length){
    box.innerHTML=`<div class="feature-unlock-card feature-unlock-done feature-unlock-done-inline">
      <h3>※プレイヤーID登録で機能解放済み</h3>
      <div class="feature-unlocked-list">${unlocked.map(x=>`<span>${escapeHtml(x)}</span>`).join("")}</div>
    </div>`;
    return;
  }
  box.innerHTML=`<div class="feature-unlock-card">
    <h3>招待ID・管理者IDで機能を解放</h3>
    <p>案内された招待IDまたは管理者IDを登録すると、このプレイヤーIDにオプション機能が解放されます。</p>
    <div class="feature-locked-note">現在、解放済みのオプション機能はありません。</div>
    <div class="invite-row">
      <input id="inviteIdInput" type="text" placeholder="招待IDまたは管理者IDを入力" autocomplete="off">
      <button id="inviteIdRedeemBtn" class="secondary" type="button">IDを登録</button>
    </div>
    <div id="inviteIdMessage" class="invite-message" aria-live="polite"></div>
  </div>`;
  const btn=$("inviteIdRedeemBtn");
  if(btn)btn.addEventListener("click",redeemInviteId);
}

function canUseAdminMode(){
  return isFeatureUnlocked("admin_mode");
}
function loadAdminMode(){
  STATE.adminMode=false;
  localStorage.setItem("adminMode","0");
  const savedMistakeReview=localStorage.getItem("mistakeReviewEnabled");
  STATE.mistakeReviewEnabled=savedMistakeReview===null?false:savedMistakeReview==="1";
}
function syncAdminModeFromFeature(){
  STATE.adminMode=canUseAdminMode();
  localStorage.setItem("adminMode",STATE.adminMode?"1":"0");
}

function syncMistakeReviewToggleUI(){
  const toggle=$("mistakeReviewToggle");
  if(toggle)toggle.checked=!!(STATE.mistakeReviewEnabled || STATE.adminMode);
}
function updateAdminModeUI(){
  if(isAdminQuestionTestMode()){
    document.body.classList.add("admin-mode-on","mistake-review-on");
    updateLoginUI();
    updateGradeOptions();
    renderAdminQuestionTestBanner();
    return;
  }
  const canAdmin=canUseAdminMode();
  STATE.adminMode=canAdmin;
  localStorage.setItem("adminMode",canAdmin?"1":"0");
  const section=$("adminModeSection");
  if(section) section.style.display=canAdmin?"block":"none";
  const status=$("adminModeStatus");
  if(status)status.textContent=canAdmin?"現在：常時オン（管理者ID・制限時間なし・成績保存なし）":"";
  const mistakeUnlocked=isMistakeReviewFeatureUnlocked();
  if(!mistakeUnlocked && STATE.mistakeReviewEnabled){
    STATE.mistakeReviewEnabled=false;
    localStorage.setItem("mistakeReviewEnabled","0");
  }
  const mistakeToggle=$("mistakeReviewToggle");
  if(mistakeToggle){
    mistakeToggle.checked=!!STATE.mistakeReviewEnabled;
    mistakeToggle.disabled=!mistakeUnlocked;
  }
  const mistakeStatus=$("mistakeReviewStatus");
  if(mistakeStatus){
    mistakeStatus.textContent=mistakeUnlocked
      ? ((STATE.mistakeReviewEnabled||STATE.adminMode)?"現在：オン（間違えた問題をこの端末に記録）":"現在：オフ")
      : "招待IDまたは管理者IDで解放すると利用できます";
  }
  document.body.classList.toggle("admin-mode-on",!!STATE.adminMode);
  document.body.classList.toggle("mistake-review-on",!!(STATE.mistakeReviewEnabled||STATE.adminMode));
  updateLoginUI();
  updateGradeOptions();
  updateRequestMenuVisibility();

  syncMistakeReviewToggleUI();
if(typeof updateOptionFeatureVisibility==="function")updateOptionFeatureVisibility();}



const REQUEST_LABELS={
  grade:{"2":"3年生以下","3":"3年生","4":"4年生","5":"5年生","6":"6年生","all":"全学年・共通"},
  position:{BASIC:"基本動作",P:"ピッチャー",C:"キャッチャー","1B":"ファースト","2B":"セカンド",SS:"ショート","3B":"サード",LF:"レフト",CF:"センター",RF:"ライト",ALL:"全ポジション・共通"},
  type:{add:"問題追加",delete:"問題削除"}
};
function requestLabel(group,value){
  return (REQUEST_LABELS[group]&&REQUEST_LABELS[group][value])||value||"-";
}
function currentRequestPlayerId(){
  return STATE.playerId||currentInputPlayerId()||"ADMIN";
}
function canCancelRequest(r){
  const mine=(r.player_id||"")===currentRequestPlayerId();
  const status=r.status||"検討中";
  return mine&&(status==="検討中"||status==="未対応");
}
function cancelButtonHtml(r){
  return canCancelRequest(r)?`<button class="request-cancel-btn" type="button" data-request-id="${escapeHtml(r.id)}">取り消す</button>`:"";
}
function updateRequestMenuVisibility(){
  const section=$("settingsRequestFormSection");
  if(section) section.style.display=(canUseAdminMode()&&STATE.adminMode)?"block":"none";
}
async function loadRequestList(){
  const list=$("requestList");
  if(!list)return;
  list.innerHTML='<div class="mypage-loading">読み込み中...</div>';
  try{
    const res=await fetch("api/list_requests.php",{cache:"no-store"});
    const data=await res.json();
    if(!res.ok||!data.ok)throw new Error((data&&data.error)||"load failed");
    const rows=Array.isArray(data.requests)?data.requests:[];
    if(!rows.length){
      list.innerHTML='<div class="mypage-empty">まだ送信された要望はありません。</div>';
      return;
    }
    list.innerHTML=`<div class="request-items">${rows.map(r=>`<div class="request-item ${(r.status==="修正反映"||r.status==="対応済み")?"done":""} ${(r.status==="取消済み"||r.status==="対応不可")?"cancelled":""}"><div class="request-item-head"><b>${escapeHtml(r.title||"無題")}</b><div class="request-actions"><span class="request-status">${escapeHtml((r.status==="未対応"?"検討中":(r.status||"検討中")))}</span>${cancelButtonHtml(r)}</div></div><div class="request-meta">${escapeHtml(r.submitted_at||"-")} / ${escapeHtml(requestLabel("grade",r.grade))} / ${escapeHtml(requestLabel("position",r.position))} / ${escapeHtml(requestLabel("type",r.request_type))}</div><div class="request-meta">送信ID：${escapeHtml(r.player_id||"-")}</div></div>`).join("")}</div>`;
    list.querySelectorAll(".request-cancel-btn").forEach(btn=>btn.addEventListener("click",()=>cancelRequest(btn.dataset.requestId)));
  }catch(e){
    console.warn("request list load failed",e);
    list.innerHTML='<div class="mypage-empty">要望データを読み込めませんでした。PHP環境とscoresフォルダの書き込み権限を確認してください。</div>';
  }
}
async function cancelRequest(requestId){
  if(!requestId)return;
  if(!confirm("この要望を取り消しますか？"))return;
  const payload={id:requestId,player_id:currentRequestPlayerId()};
  try{
    const formData=new FormData();
    Object.entries(payload).forEach(([k,v])=>formData.append(k,String(v)));
    const query=new URLSearchParams(payload).toString();
    const res=await fetch(`api/cancel_request.php?${query}`,{method:"POST",body:formData,cache:"no-store"});
    const data=await res.json().catch(()=>null);
    if(!res.ok||!data||!data.ok)throw new Error((data&&(data.message||data.error))||"cancel failed");
    const msg=$("requestMessage");
    if(msg)msg.textContent=data.message||"要望を取り消しました。";
    await loadRequestList();
  }catch(e){
    console.warn("cancel request failed",e);
    const msg=$("requestMessage");
    if(msg)msg.textContent=`取り消しに失敗しました：${e.message||"サーバー設定を確認してください。"}`;
  }
}
async function submitRequestForm(e){
  e.preventDefault();
  if(!STATE.adminMode){
    alert("要望フォームは管理者用モードONの時だけ送信できます。");
    return;
  }
  const form=$("requestForm");
  const titleEl=$("requestTitle")||(form?form.querySelector("[name='title']"):null);
  const detailEl=$("requestDetail")||(form?form.querySelector("[name='detail']"):null);
  const title=(titleEl&&typeof titleEl.value==="string"?titleEl.value:(document.querySelector("#settingsRequestFormSection input[name='title']")?.value||"")).trim();
  const detail=(detailEl&&typeof detailEl.value==="string"?detailEl.value:(document.querySelector("#settingsRequestFormSection textarea[name='detail']")?.value||"")).trim();
  const msg=$("requestMessage");
  if(!title||!detail){
    if(msg)msg.textContent="タイトルと詳細内容を入力してください。";
    return;
  }
  const payload={
    player_id:STATE.playerId||currentInputPlayerId()||"ADMIN",
    grade:$("requestGrade").value,
    position:$("requestPosition").value,
    request_type:$("requestType").value,
    title:title,
    request_title:title,
    detail:detail,
    request_detail:detail
  };
  const btn=$("requestSubmitBtn");
  if(btn)btn.disabled=true;
  if(msg)msg.textContent="送信中...";
  try{
    const formData=new FormData();
    Object.entries(payload).forEach(([k,v])=>formData.append(k,v==null?"":String(v)));
    const query=new URLSearchParams(payload).toString();
    const res=await fetch(`api/submit_request.php?${query}`,{method:"POST",body:formData,cache:"no-store"});
    let data=null;
    try{data=await res.json()}catch(_){data=null}
    if(!res.ok||!data||!data.ok){
      let serverMsg=(data&&(data.message||data.error||data.detail))?String(data.message||data.error||data.detail):"サーバーから詳細なエラーが返りませんでした。";if(serverMsg==="title and detail required")serverMsg="タイトルと詳細内容がサーバーに送信されていません。再入力して送信してください。";
      throw new Error(serverMsg);
    }
    if(msg)msg.textContent=data.message||"対応までしばしお待ちください";
    $("requestTitle").value="";
    $("requestDetail").value="";
    await loadRequestList();
  }catch(err){
    console.warn("request submit failed",err);
    if(msg)msg.textContent=`送信に失敗しました：${err.message||"scoresフォルダの書き込み権限を確認してください。"}`;
  }finally{
    if(btn)btn.disabled=false;
  }
}

function closeTopMenu(){
  const panel=$("topMenuPanel");
  const toggle=$("menuToggleBtn");
  if(panel)panel.classList.remove("open");
  if(toggle)toggle.setAttribute("aria-expanded","false");
}
function toggleTopMenu(){
  const panel=$("topMenuPanel");
  const toggle=$("menuToggleBtn");
  if(!panel||!toggle)return;
  const open=!panel.classList.contains("open");
  panel.classList.toggle("open",open);
  toggle.setAttribute("aria-expanded",open?"true":"false");
}


function pwaDisplayMode(){
  if(window.matchMedia&&window.matchMedia("(display-mode: standalone)").matches)return "pwa";
  if(window.navigator&&window.navigator.standalone)return "pwa_ios";
  return "browser";
}
function detectClientDeviceInfo(){
  const ua=navigator.userAgent||"";
  const uaData=navigator.userAgentData||null;
  const platform=(uaData&&uaData.platform)||navigator.platform||"";
  const isIOS=/iPhone|iPad|iPod/i.test(ua)||/iPhone|iPad|iPod/i.test(platform)||((platform==="MacIntel")&&navigator.maxTouchPoints>1);
  const isAndroid=/Android/i.test(ua);
  const isTablet=/iPad|Tablet/i.test(ua)||((isAndroid)&&!/Mobile/i.test(ua));
  const device_type=isTablet?"tablet":((isIOS||isAndroid||/Mobile/i.test(ua))?"mobile":"desktop");
  let os="other";
  if(isIOS)os="iOS";
  else if(isAndroid)os="Android";
  else if(/Windows/i.test(ua)||/Win/i.test(platform))os="Windows";
  else if(/Mac/i.test(platform))os="macOS";
  else if(/Linux/i.test(platform))os="Linux";
  let browser="other";
  if(/CriOS/i.test(ua))browser="Chrome iOS";
  else if(/FxiOS/i.test(ua))browser="Firefox iOS";
  else if(/EdgiOS/i.test(ua))browser="Edge iOS";
  else if(/Safari/i.test(ua)&&!/Chrome|CriOS|Chromium|Android/i.test(ua))browser="Safari";
  else if(/Edg/i.test(ua))browser="Edge";
  else if(/Chrome|Chromium/i.test(ua))browser="Chrome";
  else if(/Firefox/i.test(ua))browser="Firefox";
  return {
    display_mode:pwaDisplayMode(),
    device_type,
    os,
    browser,
    platform:String(platform||"").slice(0,80),
    ua:String(ua||"").slice(0,240)
  };
}
function trackAccessEvent(event="page_view", extra=""){
  try{
    const device=detectClientDeviceInfo();
    fetch("api/track_access.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({
        event,
        page:"index",
        player_id:STATE.playerId||currentInputPlayerId()||"",
        client_token:getClientToken(),
        grade:($("grade")&&$("grade").value)||"",
        position:STATE.position||(($("position")&&$("position").value)||""),
        display_mode:device.display_mode,
        device_type:device.device_type,
        os:device.os,
        browser:device.browser,
        platform:device.platform,
        user_agent:device.ua,
        extra
      })
    }).catch(()=>{});
  }catch(e){}
}


function isLikelyMobileOrTablet(){
  const ua=navigator.userAgent||"";
  const platform=navigator.platform||"";
  const touch=Number(navigator.maxTouchPoints||0);
  return /iPhone|iPad|iPod|Android|Mobile|Tablet/i.test(ua) || (platform==="MacIntel" && touch>1);
}
function isPwaStandalone(){
  return !!((window.matchMedia&&window.matchMedia("(display-mode: standalone)").matches) || (window.navigator&&window.navigator.standalone));
}
function isLineInAppBrowser(){
  return /Line\//i.test(navigator.userAgent||"");
}

function isIosSafariBrowser(){
  const ua=navigator.userAgent||"";
  const isIOS=/iPhone|iPad|iPod/i.test(ua) || (/Macintosh/i.test(ua) && "ontouchend" in document);
  const isSafari=/Safari/i.test(ua);
  const isOtherIOSBrowser=/CriOS|FxiOS|EdgiOS|OPiOS|YaBrowser|Line/i.test(ua);
  return isIOS && isSafari && !isOtherIOSBrowser;
}
function isAndroidChromeBrowser(){
  const ua=navigator.userAgent||"";
  return /Android/i.test(ua) && /Chrome/i.test(ua) && !/EdgA|OPR|SamsungBrowser|YaBrowser/i.test(ua);
}
function isAndroidDevice(){
  return /Android/i.test(navigator.userAgent||"");
}
function isIOSDevice(){
  const ua=navigator.userAgent||"";
  return /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/i.test(ua) && "ontouchend" in document);
}
function updatePwaHowToStepsByBrowser(){
  const iosSafariStep=$("iosSafariOpenStep");
  const androidChromeStep=$("androidChromeOpenStep");
  const iosBlock=$("iosPwaHowtoBlock");
  const androidBlock=$("androidPwaHowtoBlock");
  const isIOS=isIOSDevice();
  const isAndroid=isAndroidDevice();
  if(iosSafariStep){
    iosSafariStep.style.display=isIosSafariBrowser()?"none":"";
  }
  if(androidChromeStep){
    androidChromeStep.style.display=isAndroidChromeBrowser()?"none":"";
  }
  if(iosBlock){
    iosBlock.style.display=(isAndroid&&!isIOS)?"none":"";
  }
  if(androidBlock){
    androidBlock.style.display=(isIOS&&!isAndroid)?"none":"";
  }
}

function canRegisterPlayerOnThisEnvironment(){
  if(!isLikelyMobileOrTablet())return true;
  return isPwaStandalone() && !isLineInAppBrowser();
}
function currentPageUrlForExternalOpen(){
  return window.location.href.split("#")[0];
}
function externalBrowserUrl(){
  const url=new URL(currentPageUrlForExternalOpen(), window.location.origin);
  url.searchParams.set("openExternalBrowser","1");
  return url.toString();
}
function androidChromeIntentUrl(url){
  try{
    const u=new URL(url);
    return `intent://${u.host}${u.pathname}${u.search}#Intent;scheme=${u.protocol.replace(":","")};package=com.android.chrome;end`;
  }catch(e){
    return url;
  }
}
async function copyCurrentUrlForExternalOpen(){
  const url=externalBrowserUrl();
  try{
    if(navigator.clipboard&&navigator.clipboard.writeText){
      await navigator.clipboard.writeText(url);
      alert("URLをコピーしました。SafariまたはChromeを開いて貼り付けてください。");
      return true;
    }
  }catch(e){}
  window.prompt("このURLをコピーして、SafariまたはChromeで開いてください。",url);
  return false;
}
function handleExternalOpenLink(e){
  const url=externalBrowserUrl();
  const a=e.currentTarget;
  if(a)a.href=url;
  // LINE内ブラウザでは openExternalBrowser=1 付きリンクをそのまま開かせる。
  // 動作しない端末向けに、長押し/URLコピー導線も残す。
  return true;
}

const ISSUE_KEY_STORAGE={
  invite:"baseballPendingInviteIssueUrl",
  admin:"baseballPendingAdminIssueUrl",
  active:"baseballPendingIssueType",
  cookieInvite:"baseballPendingInviteIssueUrl",
  cookieAdmin:"baseballPendingAdminIssueUrl",
  cookieActive:"baseballPendingIssueType"
};

function currentIssueTypeFromUrl(){
  try{
    const params=new URLSearchParams(window.location.search||"");
    const issue=(params.get("issue")||params.get("id_issue")||"").toLowerCase();
    const inviteKey=params.get("invite_key")||params.get("inviteIssueKey")||params.get("issue_invite")||"";
    const adminKey=params.get("admin_key")||params.get("adminIssueKey")||params.get("issue_admin")||"";
    if(((issue==="admin")&&adminKey)||(!issue&&adminKey))return "admin";
    if(((issue==="invite")&&inviteKey)||(!issue&&inviteKey))return "invite";
  }catch(e){}
  return "";
}
function readIssueCookie(name){
  try{return decodeURIComponent(getCookieValue(name)||"");}catch(e){return getCookieValue(name)||"";}
}
function setPendingIssueKey(type,key){
  try{localStorage.setItem(ISSUE_KEY_STORAGE[type], key||"");}catch(e){}
  try{setCookieValue(ISSUE_KEY_STORAGE[type==="admin"?"cookieAdmin":"cookieInvite"], key||"", 90);}catch(e){}
}
function removePendingIssueKey(type){
  try{localStorage.removeItem(ISSUE_KEY_STORAGE[type]);}catch(e){}
  try{deleteCookieValue(ISSUE_KEY_STORAGE[type==="admin"?"cookieAdmin":"cookieInvite"]);}catch(e){}
}
function setActiveIssueType(type){
  try{
    if(type==="invite"||type==="admin"){
      localStorage.setItem(ISSUE_KEY_STORAGE.active,type);
      setCookieValue(ISSUE_KEY_STORAGE.cookieActive,type,90);
    }
  }catch(e){}
}
function getActiveIssueType(){
  let v="";
  try{v=localStorage.getItem(ISSUE_KEY_STORAGE.active)||"";}catch(e){}
  if(!v){
    v=readIssueCookie(ISSUE_KEY_STORAGE.cookieActive)||"";
    if(v==="invite"||v==="admin"){try{localStorage.setItem(ISSUE_KEY_STORAGE.active,v);}catch(e){}}
  }
  return (v==="invite"||v==="admin")?v:"";
}
function clearPendingIssueKeys(){
  try{
    localStorage.removeItem(ISSUE_KEY_STORAGE.invite);
    localStorage.removeItem(ISSUE_KEY_STORAGE.admin);
    localStorage.removeItem(ISSUE_KEY_STORAGE.active);
  }catch(e){}
  try{
    deleteCookieValue(ISSUE_KEY_STORAGE.cookieInvite);
    deleteCookieValue(ISSUE_KEY_STORAGE.cookieAdmin);
    deleteCookieValue(ISSUE_KEY_STORAGE.cookieActive);
  }catch(e){}
}
function detectIssueKeyUrl(){
  let changed=false;
  try{
    const params=new URLSearchParams(window.location.search||"");
    const issue=(params.get("issue")||params.get("id_issue")||"").toLowerCase();
    const inviteKey=params.get("invite_key")||params.get("inviteIssueKey")||params.get("issue_invite")||"";
    const adminKey=params.get("admin_key")||params.get("adminIssueKey")||params.get("issue_admin")||"";
    const hasInviteIssue=(issue==="invite"&&!!inviteKey)||(!issue&&!!inviteKey);
    const hasAdminIssue=(issue==="admin"&&!!adminKey)||(!issue&&!!adminKey);
    if(hasAdminIssue){
      setPendingIssueKey("admin", adminKey || "");
      removePendingIssueKey("invite");
      setActiveIssueType("admin");
      changed=true;
    }else if(hasInviteIssue){
      setPendingIssueKey("invite", inviteKey || "");
      removePendingIssueKey("admin");
      setActiveIssueType("invite");
      changed=true;
    }else if(getActiveIssueType() && getIssueKey(getActiveIssueType())){
      // iOSのホーム画面追加PWAでは、manifest/start_urlやlocalStorageが引き継がれず
      // 起動URLが /baseball/ だけになる場合がある。
      // そのため、招待ID/管理者ID用URLを一度開いた端末ではCookieにもキーを保持し、
      // 現在の管理画面キーとの照合を通過した場合だけ受け取りボタンを復元する。
      changed=false;
    }else{
      clearPendingIssueKeys();
      changed=true;
    }
  }catch(e){}
  return changed;
}
function getIssueKey(type){
  let v="";
  try{v=localStorage.getItem(ISSUE_KEY_STORAGE[type])||"";}catch(e){}
  if(!v){
    v=readIssueCookie(ISSUE_KEY_STORAGE[type==="admin"?"cookieAdmin":"cookieInvite"])||"";
    if(v&&v!=="1"){try{localStorage.setItem(ISSUE_KEY_STORAGE[type],v);}catch(e){}}
  }
  return v==="1"?"":v;
}
function hasIssueKey(type){
  const current=currentIssueTypeFromUrl();
  const active=getActiveIssueType();
  if(current){
    if(current!==type)return false;
    if(active&&active!==type)return false;
    return !!getIssueKey(type);
  }
  // 招待ID/管理者ID用URLを一度開いた端末では、PWA/ブラウザの起動URLから
  // queryが消えても保存キーを利用候補にする。表示前に必ず現行キー照合を行う。
  if(active===type){
    return !!getIssueKey(type);
  }
  return false;
}
const ISSUE_KEY_VALIDATION_STATE={seq:0,cache:{}};
function issueKeyValidationCacheKey(type,key){return `${type}:${key}`;}
function clearIssueKeyForType(type){
  try{
    localStorage.removeItem(ISSUE_KEY_STORAGE[type]);
    if(getActiveIssueType()===type)localStorage.removeItem(ISSUE_KEY_STORAGE.active);
  }catch(e){}
}
async function validateIssueKeyForDisplay(type){
  const key=getIssueKey(type);
  if(!key)return false;
  const cacheKey=issueKeyValidationCacheKey(type,key);
  if(Object.prototype.hasOwnProperty.call(ISSUE_KEY_VALIDATION_STATE.cache,cacheKey)){
    return !!ISSUE_KEY_VALIDATION_STATE.cache[cacheKey];
  }
  try{
    const params=new URLSearchParams({type,key});
    const res=await fetch(`api/issue_link_status.php?${params.toString()}`,{cache:"no-store"});
    const data=await res.json().catch(()=>({}));
    const valid=!!(res.ok&&data&&data.ok&&data.valid);
    ISSUE_KEY_VALIDATION_STATE.cache[cacheKey]=valid;
    if(!valid)clearIssueKeyForType(type);
    return valid;
  }catch(e){
    ISSUE_KEY_VALIDATION_STATE.cache[cacheKey]=false;
    return false;
  }
}
function issuePageExternalUrl(type){
  const page=type==="admin"?"admin_id_issue.html":"invite_issue.html";
  const url=new URL(page, window.location.href);
  url.searchParams.set("from","key_url");
  url.searchParams.set("openExternalBrowser","1");
  const issueKey=getIssueKey(type);
  if(type==="admin")url.searchParams.set("admin_key",issueKey);else url.searchParams.set("invite_key",issueKey);
  if(STATE.playerId)url.searchParams.set("player_id",STATE.playerId);
  try{url.searchParams.set("client_token",getClientToken());}catch(e){}
  return url.toString();
}
function hasAlreadyIssuedIdFeature(){
  const flags=STATE.featureFlags||{};
  return !!(flags.admin_mode||flags.mistake_review||flags.device_transfer);
}
function hasLoggedInPlayerForIssueActions(){
  // v799: 招待ID/管理者IDの取得ボタンは、ログイン済みプレイヤーIDが
  // 実際に存在する状態でのみ表示する。ID未登録・未ログイン時は、
  // キー付きURLやPWA復元キーがあっても絶対に表示しない。
  try{
    return !!(STATE.loggedIn && typeof STATE.playerId === "string" && STATE.playerId.trim() !== "");
  }catch(e){
    return false;
  }
}
function isTopScreenActive(){
  try{
    const top=$("screen-title");
    return !!(top&&top.classList.contains("active"));
  }catch(e){return false;}
}
function canShowIssueKeyActionsHere(){
  // 既存仕様：招待ID/管理者ID取得は、iPhone/iPad等ではPWAのトップに誘導して行う。
  // PCでは通常ブラウザでもID登録・取得を許可するが、モバイル/タブレットでは
  // PWAではない状態やトップ以外の画面ではボタンを表示しない。
  if(!isTopScreenActive())return false;
  if(isLikelyMobileOrTablet()&&!isPwaStandalone())return false;
  return true;
}
async function updateIssueKeyActions(){
  const box=$("issueKeyActions");
  if(!box)return;
  const seq=++ISSUE_KEY_VALIDATION_STATE.seq;
  const inviteBtn=$("inviteIssueExternalBtn");
  const adminBtn=$("adminIssueExternalBtn");
  box.style.display="none";
  if(inviteBtn)inviteBtn.style.display="none";
  if(adminBtn)adminBtn.style.display="none";

  if(!hasLoggedInPlayerForIssueActions())return;
  const alreadyIssued=hasAlreadyIssuedIdFeature();
  if(alreadyIssued)return;
  if(!canShowIssueKeyActionsHere())return;

  const currentIssue=currentIssueTypeFromUrl();
  const activeIssue=currentIssue || (isPwaStandalone()?getActiveIssueType():"");
  if(!activeIssue){
    if(!isPwaStandalone())clearPendingIssueKeys();
    return;
  }

  const wantInvite=activeIssue==="invite"&&hasIssueKey("invite");
  const wantAdmin=activeIssue==="admin"&&hasIssueKey("admin");
  const validInvite=wantInvite?await validateIssueKeyForDisplay("invite"):false;
  const validAdmin=wantAdmin?await validateIssueKeyForDisplay("admin"):false;
  if(seq!==ISSUE_KEY_VALIDATION_STATE.seq)return;

  const show=validInvite||validAdmin;
  box.style.display=show?"grid":"none";
  if(inviteBtn){
    inviteBtn.style.display=validInvite?"inline-flex":"none";
    if(validInvite){
      inviteBtn.href=issuePageExternalUrl("invite");
      inviteBtn.target="_blank";
      inviteBtn.rel="noopener";
    }
  }
  if(adminBtn){
    adminBtn.style.display=validAdmin?"inline-flex":"none";
    if(validAdmin){
      adminBtn.href=issuePageExternalUrl("admin");
      adminBtn.target="_blank";
      adminBtn.rel="noopener";
    }
  }
}
function handleIssueExternalButtonClick(e){
  const a=e.currentTarget;
  if(!hasLoggedInPlayerForIssueActions()){
    e.preventDefault();
    alert("先にプレイヤーIDを登録・ログインしてください。");
    return false;
  }
  if(a&&a.href){
    try{
      const w=window.open(a.href,"_blank","noopener");
      if(w){e.preventDefault();return false;}
    }catch(err){}
  }
  return true;
}

function updateExternalOpenLinks(){
  const href=externalBrowserUrl();
  ["openCurrentUrlSafariLink","openCurrentUrlChromeLink","lineOpenSafariLink"].forEach(id=>{
    const a=$(id);
    if(a){
      a.href=href;
      a.target="_blank";
      a.rel="noopener";
      if(!a.dataset.externalOpenBound){
        a.addEventListener("click",handleExternalOpenLink);
        a.dataset.externalOpenBound="1";
      }
    }
  });
  const copyBtn=$("copyCurrentUrlBtn");
  if(copyBtn&&!copyBtn.dataset.copyBound){
    copyBtn.addEventListener("click",copyCurrentUrlForExternalOpen);
    copyBtn.dataset.copyBound="1";
  }
  const chromeIntent=$("androidChromeIntentLink");
  if(chromeIntent){
    chromeIntent.href=androidChromeIntentUrl(href);
    chromeIntent.style.display=/Android/i.test(navigator.userAgent||"")?"inline-flex":"none";
  }
}
function updateRegistrationAvailability(){
  const isMobile=isLikelyMobileOrTablet();
  const isLine=isLineInAppBrowser();
  const standalone=isPwaStandalone();
  const canRegister=canRegisterPlayerOnThisEnvironment();
  const lineWarn=$("lineBrowserWarning");
  const pwaNotice=$("mobilePwaRequiredNotice");
  const guide=$("pwaInstallGuide");
  const controls=document.querySelectorAll(".registration-control");
  const gameControls=document.querySelectorAll(".game-start-control");
  updateExternalOpenLinks();
  updatePwaHowToStepsByBrowser();

  // 登録可能な環境では updateLoginUI() が決めた表示状態を上書きしない。
  if(!canRegister){
    controls.forEach(el=>{ el.style.display="none"; });
  }

  // スマホ・iPadの通常ブラウザでは、LINE内ブラウザに限らず
  // 学年・守備位置・ゲームスタートも非表示にする。
  // プレイヤーID登録できない状態でゲーム開始UIだけ見えると混乱するため。
  if(isMobile&&!standalone){
    gameControls.forEach(el=>{ el.style.display="none"; });
  }

  if(lineWarn)lineWarn.style.display=(isMobile&&isLine&&!standalone)?"block":"none";
  if(pwaNotice)pwaNotice.style.display=(isMobile&&!isLine&&!standalone)?"block":"none";
  if(guide&&isMobile&&!standalone){
    const dismissed=localStorage.getItem("pwaInstallGuideDismissed")==="1";
    const hasPlayer=!!(STATE.loggedIn&&STATE.playerId);
    guide.style.display=(!dismissed&&!hasPlayer)?"block":"none";
  }
  if(canRegister&&lineWarn)lineWarn.style.display="none";
  if(canRegister&&pwaNotice)pwaNotice.style.display="none";
  return canRegister;
}

function updatePwaInstallGuide(){
  const guide=$("pwaInstallGuide");
  const hasPlayer=!!(STATE.loggedIn&&STATE.playerId);
  const dismissed=localStorage.getItem("pwaInstallGuideDismissed")==="1";
  const shouldShow=isLikelyMobileOrTablet() && !isPwaStandalone() && !hasPlayer && !dismissed;
  if(guide)guide.style.display=shouldShow?"block":"none";
  updateRegistrationAvailability();
}
function togglePwaInstallSteps(){
  const box=$("pwaInstallSteps");
  if(!box)return;
  box.style.display=box.style.display==="none"||!box.style.display?"block":"none";
}
function dismissPwaInstallGuide(){
  localStorage.setItem("pwaInstallGuideDismissed","1");
  updatePwaInstallGuide();
}




let PUBLIC_VERSION_INFO=null;
function releaseTypeLabelPublic(v){return v==="major"?"メジャー更新":(v==="minor"?"機能追加・修正":"問題追加・軽微な更新")}
async function loadPublicVersionInfo(){
  try{
    const res=await fetch("api/version_info.php",{cache:"no-store"});
    const data=await res.json().catch(()=>({ok:false}));
    if(!res.ok||!data.ok)throw new Error("version load failed");
    PUBLIC_VERSION_INFO=data;
  }catch(e){
    PUBLIC_VERSION_INFO={current:{public_version:"v1.0.0",title:"正式公開版",public_summary:"少年野球シミュレーター「野球やろうぜ！」を正式公開しました。"},history:[]};
  }
  renderPublicVersionInfo();
  return PUBLIC_VERSION_INFO;
}
function renderPublicVersionInfo(){
  const data=PUBLIC_VERSION_INFO||{};
  const cur=data.current||{};
  const version=cur.public_version||"v1.0.0";
  const top=$("topPublicVersion");
  if(top)top.textContent=`${version}`;
  const notice=$("noticeVersionInfo");
  if(notice){
    const rows=(data.history||[]).slice(0,5);
    notice.innerHTML=`<h3>更新情報</h3>${rows.length?rows.map(v=>`<div class="version-history-item"><b>${escapeHtml(v.public_version||"")} ${escapeHtml(v.title||"")}</b><br><span class="muted">${escapeHtml(v.released_at||"")} / ${releaseTypeLabelPublic(v.release_type)}</span><p>${escapeHtml(v.public_summary||"").replace(/\n/g,"<br>")}</p></div>`).join(""):`<div><b>${escapeHtml(version)}</b> ${escapeHtml(cur.title||"")}</div>`}`;
  }
}

function showGameDataLoading(text){
  const overlay=$("gameDataLoadingOverlay");
  if(!overlay)return;
  const msg=overlay.querySelector(".game-data-loading-text");
  if(msg&&text)msg.textContent=text;
  overlay.style.display="flex";
}
function hideGameDataLoading(){
  const overlay=$("gameDataLoadingOverlay");
  if(overlay)overlay.style.display="none";
}

let questionLoadPromise=null;
async function ensureQuestionsLoaded(forceReload=false){
  if(forceReload){
    questionLoadPromise=null;
    STATE.questions=[];
  }
  if(Array.isArray(STATE.questions)&&STATE.questions.length)return STATE.questions;
  if(!questionLoadPromise){
    // 非公開（下書き）・停止の問題をゲームに出さないため、
    // ゲーム本体では data/questions.json を直接読まず、サーバー側で公開問題だけに絞ったAPIを読む。
    // APIが読めない場合は安全側に倒し、未フィルタのquestions.jsonへフォールバックしない。
    questionLoadPromise=fetch("api/get_game_questions.php?v=838",{cache:"no-store"})
      .then(r=>{if(!r.ok)throw new Error("published questions fetch failed");return r.json();})
      .then(data=>{
        if(!data||data.ok!==true||!Array.isArray(data.questions))throw new Error("published questions payload invalid");
        STATE.questions=data.questions;
        return STATE.questions;
      })
      .catch(e=>{questionLoadPromise=null;STATE.questions=[];throw e;});
  }
  return questionLoadPromise;
}
async function fetchJsonNoStore(url){
  const res=await fetch(url,{cache:"no-store"});
  if(!res.ok)throw new Error(`${url} fetch failed`);
  return res.json();
}
function normalizeAdminMasterQuestionRow(row){
  if(!row||typeof row!=="object")return row;
  const raw=row.raw_json&&typeof row.raw_json==="object"?row.raw_json:row;
  const positions=Array.isArray(raw.positions)?raw.positions:(typeof row.position==="string"?row.position.split("|").map(normalizeAdminQuestionTestPosition).filter(Boolean):raw.positions);
  return {
    ...raw,
    id:raw.id||row.id,
    status:row.status||raw.status||"published",
    positions
  };
}
async function loadAdminQuestionTestQuestions(){
  try{
    const data=await fetchJsonNoStore("data/questions_admin_master.json?v=838");
    if(Array.isArray(data))return data.map(normalizeAdminMasterQuestionRow);
    if(data&&Array.isArray(data.questions))return data.questions.map(normalizeAdminMasterQuestionRow);
    throw new Error("admin master payload invalid");
  }catch(e){
    console.warn("admin master questions fallback",e);
    const data=await fetchJsonNoStore("data/questions.json?v=838");
    if(Array.isArray(data))return data;
    if(data&&Array.isArray(data.questions))return data.questions;
    throw new Error("questions payload invalid");
  }
}
function adminQuestionTestPositions(q){
  if(!q||q.type!=="defense")return [];
  if(Array.isArray(q.positions)&&q.positions.length)return q.positions.map(normalizeAdminQuestionTestPosition).filter(Boolean);
  if(q.choices_by_position&&typeof q.choices_by_position==="object")return Object.keys(q.choices_by_position).map(normalizeAdminQuestionTestPosition).filter(Boolean);
  return [];
}
function makeAdminQuestionTestSequence(q,position){
  const testQuestion={...q};
  testQuestion.inning="管理者テスト";
  testQuestion.outs=hasNumericOuts(testQuestion)?Number(testQuestion.outs):(hasCommonOutsScope(testQuestion)?0:0);
  testQuestion.stage=testQuestion.stage || (testQuestion.type==="basic"?"BASIC":"none");
  testQuestion.requiredType=testQuestion.type||"admin_test";
  return [testQuestion];
}
function renderAdminQuestionTestBanner(){
  let banner=$("adminQuestionTestBanner");
  if(!isAdminQuestionTestMode()){
    if(banner)banner.style.display="none";
    document.body.classList.remove("admin-question-test-on");
    return;
  }
  if(!banner){
    banner=document.createElement("div");
    banner.id="adminQuestionTestBanner";
    banner.className="admin-question-test-banner";
    const gameShell=$("gameShell");
    if(gameShell)gameShell.insertBefore(banner,gameShell.firstChild);
    else document.body.prepend(banner);
  }
  const info=STATE.adminQuestionTestInfo||{};
  const pos=info.position?` / ${info.position}`:"";
  banner.innerHTML=`<b>管理者テストプレイ中：${escapeHtml(info.questionId||"")}${escapeHtml(pos)}</b><span>※スコア・ランキング・間違い記録には反映されません</span>`;
  banner.style.display="flex";
  document.body.classList.add("admin-question-test-on");
}
async function startAdminQuestionTest(req){
  const ok=consumeAdminQuestionTestPermit(req);
  if(!ok){
    alert("管理者テストプレイを開始できません。管理画面から起動してください。");
    return false;
  }
  window.ADMIN_QUESTION_TEST_MODE=true;
  window.ADMIN_QUESTION_TEST_INFO={questionId:req.questionId,position:req.position||""};
  STATE.adminQuestionTestMode=true;
  STATE.adminQuestionTestInfo=window.ADMIN_QUESTION_TEST_INFO;
  STATE.adminMode=true;
  localStorage.setItem("adminMode","1");
  try{
    showGameDataLoading("管理者テスト用の問題データを読み込んでいます。");
    const questions=await loadAdminQuestionTestQuestions();
    const q=questions.find(x=>normalizeAdminQuestionTestId(x&&x.id)===req.questionId);
    if(!q)throw new Error(`指定IDの問題が見つかりません：${req.questionId}`);
    if(q.type==="defense"){
      const positions=adminQuestionTestPositions(q);
      const pos=req.position || (positions.length===1?positions[0]:"");
      if(!pos)throw new Error(`${req.questionId} は守備位置を指定してください。対象：${positions.join(" / ")||"未設定"}`);
      if(positions.length&&!positions.includes(pos))throw new Error(`${req.questionId} は ${pos} の対象問題ではありません。対象：${positions.join(" / ")}`);
      STATE.position=pos;
      STATE.adminQuestionTestInfo.position=pos;
      window.ADMIN_QUESTION_TEST_INFO.position=pos;
    }else{
      STATE.position=req.position||"BASIC";
    }
    STATE.grade=Number(q.grade||3);
    STATE.questions=questions;
    STATE.sequence=makeAdminQuestionTestSequence(q,STATE.position);
    STATE.current=0;
    STATE.score=0;
    STATE.attackScore=0;
    STATE.defenseScore=0;
    STATE.logs=[];
    hideGameDataLoading();
    show("screen-game");
    renderAdminQuestionTestBanner();
    renderQuestion();
    trackAccessEvent("admin_question_test_start",`id=${req.questionId};position=${STATE.position}`);
    return true;
  }catch(e){
    hideGameDataLoading();
    console.error(e);
    alert(e.message||"管理者テストプレイを開始できませんでした。");
    show("screen-title");
    return false;
  }
}

async function init(){
  loadAdminMode();
  detectIssueKeyUrl();
  loadPublicVersionInfo().catch(()=>{});

  // ログイン状態の確定とボタン表示は、ネットワーク取得（game_config等）より先に行う。
  // キャッシュ済みフラグで即時描画し、起動ガードを外すことで描画遅延・チラつきを防ぐ。
  const savedId=localStorage.getItem("baseballPlayerId")||"";
  $("playerId").value=savedId;
  STATE.playerId=savedId;
  STATE.loggedIn=!!savedId;
  if(savedId)STATE.featureFlags=loadLocalFeatureFlags(savedId);
  loadMistakeReviewSetting(savedId);
  updateLoginUI();
  document.body.classList.remove("app-booting");

  // トップ画面を早く出すため、初期表示で不要な重い処理は後回しにする。
  // 問題データ questions.json はゲーム開始時に遅延読み込みする。
  let cfg={positions:{}};
  try{
    cfg=await fetch("data/game_config.json?v=838").then(r=>r.json());
  }catch(e){
    console.warn("game config load failed",e);
  }
  STATE.config=cfg;

  const sel=$("position");
  if(sel && cfg.positions && !sel.dataset.loaded){
    Object.entries(cfg.positions).forEach(([k,v])=>{
      const opt=document.createElement("option");
      opt.value=k;
      opt.textContent=v;
      sel.appendChild(opt);
    });
    sel.dataset.loaded="1";
  }

  updateGradeOptions();

  // サーバー確認系はトップ表示をブロックしない。
  if(savedId){
    loadPlayerProgress(savedId);
    refreshFeatureFlags(savedId).then(()=>{
      loadMistakeReviewSetting(savedId);
      loadOwnServerMistakes(savedId);
      if(document.body.classList.contains("screen-mypage-active")){
        renderFeatureUnlockSection();
        renderMistakeReviewSection();
      }
    }).catch(()=>{});
  }

  // アクセス記録も初期表示後に送る。
  setTimeout(()=>trackAccessEvent("page_view","init"),0);

  handleInitialOpenAction();

  $("position").addEventListener("change",updateGradeOptions);
  $("grade").addEventListener("change",updateGradeOptions);
  $("playerId").addEventListener("input",()=>{STATE.loggedIn=false;STATE.playerId="";STATE.progress={};STATE.featureFlags={};STATE.featureStatus=null;updateLoginUI();updateGradeOptions();updateAdminModeUI()});
  $("loginBtn").addEventListener("click",loginWithCurrentId);
  const inviteIssueExternalBtn=$("inviteIssueExternalBtn");if(inviteIssueExternalBtn)inviteIssueExternalBtn.addEventListener("click",handleIssueExternalButtonClick);
  const adminIssueExternalBtn=$("adminIssueExternalBtn");if(adminIssueExternalBtn)adminIssueExternalBtn.addEventListener("click",handleIssueExternalButtonClick);
  $("myPageBtn").addEventListener("click",()=>{closeTopMenu();openMyPage()});
  $("rankingBtn").addEventListener("click",openRanking);
  const quizMasterBtn=$("quizMasterBtn");if(quizMasterBtn)quizMasterBtn.addEventListener("click",()=>{closeTopMenu();openQuizMasterMenu()});
  const quizMasterRankingBtn=$("quizMasterRankingBtn");if(quizMasterRankingBtn)quizMasterRankingBtn.addEventListener("click",()=>{closeTopMenu();openQuizMasterRanking()});
  const noticesMenuBtn=$("noticesMenuBtn");if(noticesMenuBtn)noticesMenuBtn.addEventListener("click",openNotices);
  $("logoutBtn").addEventListener("click",()=>{closeTopMenu();logoutPlayer()});
  $("myPageBackBtn").addEventListener("click",()=>show("screen-title"));
  $("rankingBackBtn").addEventListener("click",()=>show("screen-title"));
  const quizMasterExitBtn=$("quizMasterExitBtn");if(quizMasterExitBtn)quizMasterExitBtn.addEventListener("click",async()=>{
    clearQuizMasterTimer(); // 警告表示中は制限時間を停止する
    const ok=await confirmQuizMasterExitWarning();
    if(ok){
      clearQuizMasterTimer();
      show("screen-quiz-master-menu");
    }else{
      resumeQuizMasterTimerAfterPrompt(); // ゲームに戻る場合は再開
    }
  });
  const quizMasterResultBackBtn=$("quizMasterResultBackBtn");if(quizMasterResultBackBtn)quizMasterResultBackBtn.addEventListener("click",openQuizMasterMenu);
  const quizMasterRetryBtn=$("quizMasterRetryBtn");if(quizMasterRetryBtn)quizMasterRetryBtn.addEventListener("click",startQuizMaster);
  const quizMasterRankingBackBtn=$("quizMasterRankingBackBtn");if(quizMasterRankingBackBtn)quizMasterRankingBackBtn.addEventListener("click",()=>show("screen-quiz-master-menu"));
  const quizMasterRankingPlayBtn=$("quizMasterRankingPlayBtn");if(quizMasterRankingPlayBtn)quizMasterRankingPlayBtn.addEventListener("click",startQuizMaster);
  const quizMasterMenuStartBtn=$("quizMasterMenuStartBtn");if(quizMasterMenuStartBtn)quizMasterMenuStartBtn.addEventListener("click",startQuizMaster);
  const quizMasterMenuRankingBtn=$("quizMasterMenuRankingBtn");if(quizMasterMenuRankingBtn)quizMasterMenuRankingBtn.addEventListener("click",openQuizMasterRanking);
  const quizMasterMenuRanksBtn=$("quizMasterMenuRanksBtn");if(quizMasterMenuRanksBtn)quizMasterMenuRanksBtn.addEventListener("click",showQuizMasterRanks);
  const quizMasterMenuHowtoBtn=$("quizMasterMenuHowtoBtn");if(quizMasterMenuHowtoBtn)quizMasterMenuHowtoBtn.addEventListener("click",async()=>{show("screen-quiz-master");await new Promise(r=>setTimeout(r,50));showQuizMasterTutorial(true);});
  const quizMasterMenuBackBtn=$("quizMasterMenuBackBtn");if(quizMasterMenuBackBtn)quizMasterMenuBackBtn.addEventListener("click",()=>show("screen-title"));
  const quizMasterRanksBackBtn=$("quizMasterRanksBackBtn");if(quizMasterRanksBackBtn)quizMasterRanksBackBtn.addEventListener("click",openQuizMasterMenu);
  const quizMasterFiftyBtn=$("quizMasterFiftyBtn");if(quizMasterFiftyBtn)quizMasterFiftyBtn.addEventListener("click",useQuizMasterFifty);
  const noticesBackBtn=$("noticesBackBtn");if(noticesBackBtn)noticesBackBtn.addEventListener("click",()=>show("screen-title"));
  $("resultMyPageBtn").addEventListener("click",openMyPage);
  $("startBtn").addEventListener("click",startGame);
  const interruptBtn=$("interruptGameBtn");if(interruptBtn)interruptBtn.addEventListener("click",interruptGame);
  $("howBtn").addEventListener("click",()=>{closeTopMenu();show("screen-how")});
  $("guestHowBtn").addEventListener("click",()=>show("screen-how"));
  const pwaHow=$("pwaGuideHowBtn");if(pwaHow)pwaHow.addEventListener("click",togglePwaInstallSteps);
  const pwaLater=$("pwaGuideLaterBtn");if(pwaLater)pwaLater.addEventListener("click",dismissPwaInstallGuide);
  $("settingsBtn").addEventListener("click",()=>{
    closeTopMenu();
    show("screen-settings");
    loadMistakeReviewSetting(STATE.playerId);
    updateAdminModeUI();
    updatePushSectionAvailability();
    refreshPushStatus();
    if(canUseAdminMode()&&STATE.adminMode) loadRequestList();
    if(STATE.playerId){
      refreshFeatureFlags(STATE.playerId).then(()=>{
        loadMistakeReviewSetting(STATE.playerId);
        updateAdminModeUI();
        if(canUseAdminMode()&&STATE.adminMode) loadRequestList();
        renderMistakeReviewSection();
      }).catch(e=>console.warn("settings feature refresh failed",e));
    }
  });
  const requestFormEl=$("requestForm");if(requestFormEl)requestFormEl.addEventListener("submit",submitRequestForm);
  $("settingsBackBtn").addEventListener("click",()=>show("screen-title"));
  const mistakeToggle=$("mistakeReviewToggle");if(mistakeToggle)mistakeToggle.addEventListener("change",e=>setMistakeReviewEnabled(e.target.checked));
  const changeIdBtn=$("changePlayerIdBtn");if(changeIdBtn)changeIdBtn.addEventListener("click",changePlayerId);
  const enablePushBtn=$("enablePushBtn");if(enablePushBtn)enablePushBtn.addEventListener("click",enablePushNotifications);
  $("menuToggleBtn").addEventListener("click",toggleTopMenu);
  document.addEventListener("click",e=>{const panel=$("topMenuPanel");const toggle=$("menuToggleBtn");if(panel&&toggle&&panel.classList.contains("open")&&!panel.contains(e.target)&&!toggle.contains(e.target))closeTopMenu()});
  document.querySelectorAll("[data-back-title]").forEach(b=>b.addEventListener("click",()=>show("screen-title")));
  $("retryBtn").addEventListener("click",()=>show("screen-title"));
  updateAdminModeUI();
  updateRequestMenuVisibility();
  updatePushSectionAvailability();
  document.body.classList.add("screen-title-active");
  const adminTestReq=readAdminQuestionTestRequest();
  if(adminTestReq)await startAdminQuestionTest(adminTestReq);
}

function clearQuizMasterTimer(){
  if(QUIZ_MASTER_STATE.timer){
    clearInterval(QUIZ_MASTER_STATE.timer);
    QUIZ_MASTER_STATE.timer=null;
  }
  hideQuizMasterCountdown();
}
function wait(ms){return new Promise(resolve=>setTimeout(resolve,ms))}
function quizMasterShell(){return document.querySelector(".quiz-master-shell")}
function hideQuizMasterCountdown(){
  const el=$("quizMasterCountdownOverlay");
  if(!el)return;
  el.classList.remove("show","danger");
  el.textContent="";
}
function updateQuizMasterCountdown(){
  const el=$("quizMasterCountdownOverlay");
  if(!el)return;
  const remaining=Math.max(0,Number(QUIZ_MASTER_STATE.remaining)||0);
  if(QUIZ_MASTER_STATE.answered||QUIZ_MASTER_STATE.animating||remaining>10||remaining<=0){
    hideQuizMasterCountdown();
    return;
  }
  el.textContent=String(remaining);
  el.classList.toggle("danger",remaining<=3);
  el.classList.remove("show");
  void el.offsetWidth;
  el.classList.add("show");
}
function clearQuizMasterStageClasses(){
  const shell=quizMasterShell();
  if(shell)shell.classList.remove("quiz-master-round-intro","quiz-master-round-exit","quiz-master-starting");
  const panel=document.querySelector(".quiz-master-question-panel");
  if(panel)panel.classList.remove("is-entering","is-exiting");
  document.querySelectorAll(".quiz-master-choice").forEach(btn=>btn.classList.remove("is-entering","is-exiting"));
  const startOverlay=$("quizMasterStartOverlay");
  if(startOverlay)startOverlay.classList.remove("show");
  const tutorialOverlay=$("quizMasterTutorialOverlay");
  if(tutorialOverlay)tutorialOverlay.classList.remove("show");
  const checkpointOverlay=$("quizMasterCheckpointOverlay");
  if(checkpointOverlay)checkpointOverlay.classList.remove("show");
  const fiftyOverlay=$("quizMasterFiftyConfirmOverlay");
  if(fiftyOverlay)fiftyOverlay.classList.remove("show");
  hideQuizMasterCountdown();
}
function quizMasterPointForLevel(level){
  const n=Number(level);
  if(!Number.isFinite(n)||n<1||n>QUIZ_MASTER_TOTAL_QUESTIONS)return 0;
  const base=(5*n*n)-(5*n)+10;
  const multiplier=n>=QUIZ_MASTER_CHALLENGE_START_LEVEL
    ? 1+((n-QUIZ_MASTER_CHALLENGE_START_LEVEL+1)*QUIZ_MASTER_CHALLENGE_MULTIPLIER_STEP)
    : 1;
  return Math.round(base*multiplier);
}
function quizMasterTimeForLevel(level){return QUIZ_MASTER_TIME_LIMITS[Number(level)]||15}
function quizMasterTodayKey(){
  const now=new Date();
  const jst=new Date(now.getTime()+9*60*60*1000);
  return jst.toISOString().slice(0,10);
}
function quizMasterLimitPlayerKey(){
  const pid=STATE.loggedIn&&STATE.playerId?STATE.playerId:(currentInputPlayerId&&currentInputPlayerId()?currentInputPlayerId():"guest");
  return String(pid||"guest").toUpperCase();
}
function quizMasterDailyStorageKey(){
  return `baseballQuizMasterDaily:${quizMasterLimitPlayerKey()}:${quizMasterTodayKey()}`;
}
function quizMasterQuestionHistoryStorageKey(){
  return `baseballQuizMasterQuestionHistory:${quizMasterLimitPlayerKey()}:${quizMasterTodayKey()}`;
}
function quizMasterTutorialStorageKey(){
  return `baseballQuizMasterTutorialSeen:${quizMasterLimitPlayerKey()}`;
}
function quizMasterHasSeenTutorial(){
  try{
    return localStorage.getItem(quizMasterTutorialStorageKey())==="1";
  }catch(e){
    return false;
  }
}
function quizMasterMarkTutorialSeen(){
  try{
    localStorage.setItem(quizMasterTutorialStorageKey(),"1");
  }catch(e){}
}
function quizMasterBonusLifeStorageKey(){
  return `baseballQuizMasterBonusLife:${quizMasterLimitPlayerKey()}:${quizMasterTodayKey()}`;
}
function quizMasterHasBonusLifeToday(){
  try{return localStorage.getItem(quizMasterBonusLifeStorageKey())==="1";}catch(e){return false;}
}
// 通常の野球やろうぜ！を1日1回完了したとき、その日だけ野球博士チャレンジのライフを+1する（当日初回のみ加算）。
function quizMasterGrantBonusLifeToday(){
  try{
    if(localStorage.getItem(quizMasterBonusLifeStorageKey())==="1")return false;
    localStorage.setItem(quizMasterBonusLifeStorageKey(),"1");
    return true;
  }catch(e){return false;}
}
// 当日の実効ライフ上限（基本5 + 通常ゲーム完了ボーナス）。
function quizMasterDailyLimitToday(){
  return QUIZ_MASTER_DAILY_LIMIT+(quizMasterHasBonusLifeToday()?1:0);
}
function quizMasterReadDailyUsed(){
  try{
    const n=Number(localStorage.getItem(quizMasterDailyStorageKey())||"0");
    return Number.isFinite(n)?Math.max(0,Math.min(quizMasterDailyLimitToday(),n)):0;
  }catch(e){
    return 0;
  }
}
function quizMasterRemainingToday(){
  if(!QUIZ_MASTER_DAILY_LIMIT_ENABLED)return quizMasterDailyLimitToday();
  return Math.max(0,quizMasterDailyLimitToday()-quizMasterReadDailyUsed());
}
function quizMasterConsumeDailyAttempt(){
  const limitToday=quizMasterDailyLimitToday();
  if(!QUIZ_MASTER_DAILY_LIMIT_ENABLED)return {ok:true,remaining:limitToday};
  if(STATE.adminMode)return {ok:true,remaining:limitToday};
  const used=quizMasterReadDailyUsed();
  if(used>=limitToday)return {ok:false,remaining:0};
  try{
    localStorage.setItem(quizMasterDailyStorageKey(),String(used+1));
  }catch(e){}
  return {ok:true,remaining:Math.max(0,limitToday-used-1)};
}
function quizMasterReadTodayQuestionHistory(){
  try{
    const raw=localStorage.getItem(quizMasterQuestionHistoryStorageKey())||"[]";
    const arr=JSON.parse(raw);
    return Array.isArray(arr)?arr.map(id=>String(id||"").trim()).filter(Boolean):[];
  }catch(e){
    return [];
  }
}
function quizMasterSaveTodayQuestionHistory(ids){
  try{
    const unique=Array.from(new Set((ids||[]).map(id=>String(id||"").trim()).filter(Boolean)));
    localStorage.setItem(quizMasterQuestionHistoryStorageKey(),JSON.stringify(unique.slice(-400)));
  }catch(e){}
}
function quizMasterMarkQuestionShown(q){
  if(!q||!q.id)return;
  const ids=quizMasterReadTodayQuestionHistory();
  if(ids.includes(q.id))return;
  ids.push(q.id);
  quizMasterSaveTodayQuestionHistory(ids);
}
function hasQuizMasterFeatureAccess(){
  if(isFeatureUnlocked("quiz_master"))return true;
  if(STATE.adminMode||isFeatureUnlocked("admin_mode"))return true;
  return false;
}
async function ensureQuizMasterProductionAccess(){
  if(!QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED)return true;
  if(!STATE.loggedIn||!STATE.playerId){
    alert("野球博士チャレンジは、プレイヤーIDでログインし、オプション機能を解放した方のみ利用できます。");
    return false;
  }
  try{await refreshFeatureFlags(STATE.playerId);}catch(e){console.warn("quiz master access refresh failed",e)}
  if(!hasQuizMasterFeatureAccess()){
    alert("野球博士チャレンジはオプション機能です。マイページで招待IDまたは管理者IDを登録し、野球博士チャレンジ機能を解放してください。");
    return false;
  }
  return true;
}
function renderQuizMasterStartButton(label){
  const start=$("quizMasterBtn");
  if(!start)return;
  start.textContent="";
  const title=document.createElement("span");
  title.className="quiz-master-start-title";
  title.textContent=label;
  const badge=document.createElement("span");
  badge.className="quiz-master-start-new";
  badge.textContent="NEW!";
  start.append(title,badge);
}
function updateQuizMasterDailyUI(){
  const remaining=STATE.adminMode?QUIZ_MASTER_DAILY_LIMIT:quizMasterRemainingToday();
  const life=$("quizMasterLifelineBtn");
  if(life)life.innerHTML=QUIZ_MASTER_DAILY_LIMIT_ENABLED?`ライフ <span class="qm-life-heart">♥</span>×${remaining}<br><small>毎日24時リセット</small>`:`ライフ <span class="qm-life-heart">♥</span>×∞<br><small>テスト中は無制限</small>`;
  const start=$("quizMasterBtn");
  if(start){
    start.disabled=QUIZ_MASTER_DAILY_LIMIT_ENABLED&&!STATE.adminMode&&remaining<=0;
    renderQuizMasterStartButton(QUIZ_MASTER_DAILY_LIMIT_ENABLED?(remaining>0||STATE.adminMode?`野球博士チャレンジ（本日残り${remaining}回）`:"野球博士チャレンジ（本日終了）"):"野球博士チャレンジ");
  }
}
function showQuizMasterTutorial(fromMenu=false){
  return new Promise(resolve=>{
    const overlay=$("quizMasterTutorialOverlay");
    if(!overlay){resolve(true);return}
    const topButtonText=fromMenu?"メニューに戻る":"トップに戻る";
    const startButtonHtml=fromMenu?"":'<button type="button" class="secondary quiz-master-lifeline" data-quiz-tutorial="start">ゲームを始める</button>';
    overlay.innerHTML='<div class="quiz-master-tutorial-card" role="dialog" aria-modal="true" aria-label="野球博士チャレンジ はじめに"><p class="quiz-master-tutorial-kicker">はじめに</p><h2>野球博士チャレンジ</h2><div class="quiz-master-tutorial-body"><p>少年野球・学童野球の基本ルール、用具、安全知識から、高度な競技規則、高校野球、プロ野球、国際大会、野球記録まで、幅広い野球知識が問われるクイズです。</p><ul><li>全20問。各問に制限時間があります。</li><li>正解するたびに点数が加算され、後半ほど1問あたりの点数が大きく伸びます。</li><li>第15問以降はチャレンジゾーンです。正解するたびに加算率が上がり、得点の伸びがさらに強くなります。</li><li class="quiz-master-tutorial-danger">第15問以降で失敗すると獲得点数は0点になります。</li><li><span class="quiz-master-tutorial-life">本番では1日5ライフまで。通常の野球やろうぜ！を1回クリアすると、その日だけライフが1つ増えます。毎日24時にリセットされます（テスト中は無制限）。</span></li><li>獲得スコアに応じて、野球博士ランク1〜20が決定されます。ランキングで他のプレイヤーと競い、自分の順位を確認できます。</li><li><span class="quiz-master-tutorial-life">50:50は1ゲームに1回だけ、誤答を1つ消せます。</span></li></ul></div><div class="quiz-master-tutorial-actions"><button type="button" class="secondary" data-quiz-tutorial="top">'+topButtonText+'</button>'+startButtonHtml+'</div></div>';
    overlay.setAttribute("aria-hidden","false");
    overlay.classList.add("show");
    overlay.querySelector('[data-quiz-tutorial="top"]')?.addEventListener("click",()=>{
      overlay.classList.remove("show");
      overlay.setAttribute("aria-hidden","true");
      overlay.innerHTML="";
      if(fromMenu)show("screen-quiz-master-menu");
      resolve(false);
    },{once:true});
    overlay.querySelector('[data-quiz-tutorial="start"]')?.addEventListener("click",()=>{
      quizMasterMarkTutorialSeen();
      overlay.classList.remove("show");
      overlay.setAttribute("aria-hidden","true");
      overlay.innerHTML="";
      resolve(true);
    },{once:true});
  });
}
async function ensureQuizMasterTutorial(){
  if(quizMasterHasSeenTutorial())return true;
  show("screen-quiz-master");
  updateQuizMasterDailyUI();
  setTextSafe("quizMasterQuestion","野球博士チャレンジを始めます。");
  setTextSafe("quizMasterMessage","初回のはじめにを確認してください。");
  const choices=$("quizMasterChoices");if(choices)choices.innerHTML="";
  return await showQuizMasterTutorial();
}
function normalizeQuizMasterQuestions(payload){
  const rows=Array.isArray(payload)?payload:(Array.isArray(payload&&payload.questions)?payload.questions:[]);
  return rows.map(q=>({
    id:String(q&&q.id||"").trim(),
    level:Number(q&&q.level||0),
    category:String(q&&q.category||"").trim(),
    question:String(q&&q.question||"").trim(),
    choices:Array.isArray(q&&q.choices)?q.choices.map(c=>String(c||"")):[],
    answer:Number(q&&q.answer),
    explanation:String(q&&q.explanation||"").trim()
  })).filter(q=>q.id&&q.level>=1&&q.level<=QUIZ_MASTER_TOTAL_QUESTIONS&&q.question&&q.choices.length===3&&q.answer>=0&&q.answer<3);
}
async function loadQuizMasterQuestions(){
  if(QUIZ_MASTER_STATE.questions.length)return QUIZ_MASTER_STATE.questions;
  let data=null;
  const candidates=[
    "data/quiz_master_questions.json?v=1016",
    "./data/quiz_master_questions.json?v=1016",
    new URL("data/quiz_master_questions.json?v=1016",document.baseURI).href
  ];
  for(const url of Array.from(new Set(candidates))){
    try{
      const res=await fetch(url,{cache:"no-store"});
      if(res.ok){data=await res.json();break}
    }catch(e){
      console.warn("quiz data fetch candidate failed",url,e);
    }
  }
  if(!data&&window.QUIZ_MASTER_QUESTIONS){
    data=window.QUIZ_MASTER_QUESTIONS;
  }
  if(!data)throw new Error("quiz data load failed");
  const rows=normalizeQuizMasterQuestions(data);
  QUIZ_MASTER_STATE.questions=rows;
  return rows;
}
async function loadQuizMasterQuestionStats(){
  try{
    const res=await fetch("api/get_quiz_master_question_stats.php?v=1016",{cache:"no-store"});
    const data=await res.json();
    QUIZ_MASTER_STATE.questionStats=(res.ok&&data&&data.ok&&data.stats&&typeof data.stats==="object")?data.stats:{};
  }catch(e){
    console.warn("quiz question stats fetch failed",e);
    QUIZ_MASTER_STATE.questionStats={};
  }
  return QUIZ_MASTER_STATE.questionStats;
}
function quizMasterCorrectRateText(q){
  const stat=q&&q.id?QUIZ_MASTER_STATE.questionStats[q.id]:null;
  const attempts=Number(stat&&stat.attempts||0);
  const rate=Number(stat&&stat.correct_rate);
  if(!attempts||!Number.isFinite(rate))return "";
  return `（正解率${Math.round(rate)}%）`;
}
function normalizeQuizMasterTitles(rows){
  const list=(Array.isArray(rows)?rows:QUIZ_MASTER_TITLE_DEFAULTS).map(row=>({
    title:String(row&&row.title||"").trim(),
    point:Math.max(0,Number(row&&row.point||0))
  })).filter(row=>row.title&&Number.isFinite(row.point));
  list.sort((a,b)=>a.point-b.point||a.title.localeCompare(b.title,"ja"));
  const out=list.length?list:QUIZ_MASTER_TITLE_DEFAULTS.slice();
  out.forEach((row,i)=>{row.level=i+1;});
  return out;
}
function quizMasterLevelIconUrl(level){
  const lv=Math.max(1,Math.min(20,Number(level)||1));
  return `assets/quiz_icon/${lv}.png`;
}
function quizMasterLevelIconHtml(level,cssClass){
  return `<img src="${quizMasterLevelIconUrl(level)}" class="qm-level-icon${cssClass?' '+cssClass:''}" alt="" loading="eager">`;
}
async function loadQuizMasterTitles(){
  if(QUIZ_MASTER_STATE.titles.length)return QUIZ_MASTER_STATE.titles;
  try{
    const res=await fetch("api/get_quiz_master_titles.php?v=1016",{cache:"no-store"});
    const data=await res.json();
    QUIZ_MASTER_STATE.titles=normalizeQuizMasterTitles(res.ok&&data&&data.ok?data.titles:null);
  }catch(e){
    console.warn("quiz title fetch failed",e);
    QUIZ_MASTER_STATE.titles=normalizeQuizMasterTitles();
  }
  return QUIZ_MASTER_STATE.titles;
}
function quizMasterTitleForScore(score,titles){
  const rows=normalizeQuizMasterTitles(titles||QUIZ_MASTER_STATE.titles);
  const n=Number(score||0);
  let current=rows[0];
  rows.forEach(row=>{if(n>=row.point)current=row});
  return current;
}
function quizMasterTitleBadgeHtml(score,titles){
  const info=quizMasterTitleForScore(score,titles);
  return `<span class="quiz-master-title-badge">${quizMasterLevelIconHtml(info.level,'qm-icon-sm')}${escapeHtml(info.title)}</span>`;
}
function quizMasterNewTitlesBetween(before,after,titles){
  const b=Number(before||0),a=Number(after||0);
  if(a<=b)return [];
  return normalizeQuizMasterTitles(titles).filter(row=>row.point>b&&row.point<=a);
}
function pickQuizMasterSequence(rows){
  const sequence=[];
  const used=new Set();
  const shownToday=new Set(quizMasterReadTodayQuestionHistory());
  for(let level=1;level<=QUIZ_MASTER_TOTAL_QUESTIONS;level++){
    let pool=rows.filter(q=>q.level===level&&!used.has(q.id)&&!shownToday.has(q.id));
    if(!pool.length)pool=rows.filter(q=>q.level===level&&!used.has(q.id));
    if(!pool.length)throw new Error(`第${level}問の問題プールがありません。`);
    const q=pool[Math.floor(Math.random()*pool.length)];
    used.add(q.id);
    sequence.push(q);
  }
  validateQuizMasterSequence(sequence);
  return sequence;
}
function validateQuizMasterSequence(sequence){
  if(!Array.isArray(sequence)||sequence.length!==QUIZ_MASTER_TOTAL_QUESTIONS){
    throw new Error("野球博士チャレンジの出題数が不正です。");
  }
  sequence.forEach((q,idx)=>{
    const expected=idx+1;
    if(!q||Number(q.level)!==expected){
      const actual=q&&q.level!==undefined?q.level:"未設定";
      const id=q&&q.id?q.id:"IDなし";
      throw new Error(`野球博士チャレンジの出題レベル不整合: 第${expected}問に level ${actual} (${id}) が選ばれました。`);
    }
  });
}
async function startQuizMaster(){
  const guestTest=!(STATE.loggedIn&&STATE.playerId);
  closeTopMenu();
  clearQuizMasterTimer();
  if(!(await ensureQuizMasterProductionAccess())){
    show("screen-title");
    return;
  }
  if(!(await ensureQuizMasterTutorial())){
    show("screen-title");
    return;
  }
  if(QUIZ_MASTER_DAILY_LIMIT_ENABLED&&!STATE.adminMode&&quizMasterRemainingToday()<=0){
    updateQuizMasterDailyUI();
    const status=$("loginStatus");
    if(status)status.textContent="野球博士チャレンジは本日のライフを使い切りました。毎日24時にリセットされます。通常の野球やろうぜ！を1回クリアすると、本日だけライフが1つ増えます。";
    alert("野球博士チャレンジは本日のライフを使い切りました。毎日24時にリセットされます。通常の野球やろうぜ！を1回クリアすると、本日だけライフが1つ増えます。");
    show("screen-title");
    return;
  }
  Object.assign(QUIZ_MASTER_STATE,{sequence:[],currentIndex:0,score:0,selected:null,remaining:20,answered:false,startedAt:Date.now(),questionStartedAt:0,logs:[],challenge:false,guestTest,animating:false,roundToken:0,endReason:"",fiftyUsed:false,fiftyHidden:null,failureReview:null,fiftyPromptOpen:false,choiceOrder:[]});
  show("screen-quiz-master");
  updateQuizMasterDailyUI();
  setTextSafe("quizMasterQuestion","問題データを読み込み中...");
  setTextSafe("quizMasterMessage",guestTest?"テストプレイ中です。結果はランキングに保存されません。":"");
  setTextSafe("quizMasterScore","0");
  const choices=$("quizMasterChoices");if(choices)choices.innerHTML="";
  try{
    const [rows]=await Promise.all([loadQuizMasterQuestions(),loadQuizMasterQuestionStats(),loadQuizMasterTitles()]);
    const attempt=quizMasterConsumeDailyAttempt();
    if(!attempt.ok){
      updateQuizMasterDailyUI();
      show("screen-title");
      return;
    }
    updateQuizMasterDailyUI();
    QUIZ_MASTER_STATE.sequence=pickQuizMasterSequence(rows);
    renderQuizMasterQuestion();
  }catch(e){
    console.warn("quiz master start failed",e);
    setTextSafe("quizMasterQuestion","問題データを読み込めませんでした。");
    setTextSafe("quizMasterMessage",e&&e.message?e.message:"data/quiz_master_questions.json を確認してください。");
  }
}
function setTextSafe(id,text){const el=$(id);if(el)el.textContent=text}
function currentQuizMasterQuestion(){return QUIZ_MASTER_STATE.sequence[QUIZ_MASTER_STATE.currentIndex]||null}
function buildQuizMasterChoiceOrder(q){
  return shuffle((Array.isArray(q&&q.choices)?q.choices:[]).map((text,originalIndex)=>({
    text:String(text||""),
    originalIndex,
    isAnswer:originalIndex===Number(q&&q.answer)
  })));
}
function currentQuizMasterChoiceOrder(){return Array.isArray(QUIZ_MASTER_STATE.choiceOrder)?QUIZ_MASTER_STATE.choiceOrder:[]}
function currentQuizMasterAnswerIndex(){return currentQuizMasterChoiceOrder().findIndex(choice=>choice&&choice.isAnswer)}
function renderQuizMasterQuestion(){
  const q=currentQuizMasterQuestion();
  if(!q){finishQuizMaster(true);return}
  const questionNo=QUIZ_MASTER_STATE.currentIndex+1;
  if(Number(q.level)!==questionNo){
    console.error("quiz master level mismatch",{questionNo,question:q});
    finishQuizMaster(false,"問題レベルの不整合を検出したため終了しました。","level_mismatch");
    return;
  }
  quizMasterMarkQuestionShown(q);
  clearQuizMasterTimer();
  clearQuizMasterStageClasses();
  QUIZ_MASTER_STATE.selected=null;
  QUIZ_MASTER_STATE.answered=false;
  QUIZ_MASTER_STATE.animating=true;
  QUIZ_MASTER_STATE.fiftyHidden=null;
  QUIZ_MASTER_STATE.choiceOrder=buildQuizMasterChoiceOrder(q);
  QUIZ_MASTER_STATE.roundToken+=1;
  QUIZ_MASTER_STATE.remaining=quizMasterTimeForLevel(questionNo);
  QUIZ_MASTER_STATE.questionStartedAt=0;
  setTextSafe("quizMasterLevel",String(questionNo));
  setTextSafe("quizMasterProgress",`${questionNo}/${QUIZ_MASTER_TOTAL_QUESTIONS}`);
  setTextSafe("quizMasterScore",String(QUIZ_MASTER_STATE.score));
  setTextSafe("quizMasterTimer",String(QUIZ_MASTER_STATE.remaining));
  const prefix=QUIZ_MASTER_STATE.guestTest?"テストプレイ / ":"";
  setTextSafe("quizMasterMessage",q.category?`${prefix}カテゴリ: ${q.category}`:(QUIZ_MASTER_STATE.guestTest?"テストプレイ中です。結果はランキングに保存されません。":""));
  const rateText=quizMasterCorrectRateText(q);
  setTextSafe("quizMasterQuestion",rateText?`${q.question} ${rateText}`:q.question);
  const box=$("quizMasterChoices");
  if(box){
    box.innerHTML="";
    currentQuizMasterChoiceOrder().forEach((choice,idx)=>{
      const btn=document.createElement("button");
      btn.type="button";
      btn.className=`quiz-master-choice choice-${idx}`;
      btn.style.setProperty("--quiz-choice-order",String(idx));
      btn.innerHTML=`<span class="quiz-master-choice-label">${String.fromCharCode(65+idx)}</span><b class="quiz-master-choice-text">${escapeHtml(choice.text)}</b>`;
      btn.addEventListener("click",()=>selectQuizMasterChoice(idx));
      box.appendChild(btn);
    });
  }
  startQuizMasterRoundIntro(QUIZ_MASTER_STATE.roundToken);
  updateQuizMasterFiftyButton();
}
async function startQuizMasterRoundIntro(token){
  const shell=quizMasterShell();
  const panel=document.querySelector(".quiz-master-question-panel");
  const choices=Array.from(document.querySelectorAll(".quiz-master-choice"));
  const q=currentQuizMasterQuestion();
  if(!q)return;
  if(shell)shell.classList.add("quiz-master-round-intro");
  if(panel)panel.classList.add("is-entering");
  choices.forEach(btn=>btn.classList.add("is-entering"));
  await wait(1250);
  if(token!==QUIZ_MASTER_STATE.roundToken||$("screen-quiz-master")?.classList.contains("active")===false)return;
  const startOverlay=$("quizMasterStartOverlay");
  if(q.level===1&&startOverlay){
    if(shell)shell.classList.add("quiz-master-starting");
    startOverlay.classList.remove("show");
    void startOverlay.offsetWidth;
    startOverlay.classList.add("show");
    await wait(720);
  }else{
    if(startOverlay)startOverlay.classList.remove("show");
    await wait(120);
  }
  if(token!==QUIZ_MASTER_STATE.roundToken||$("screen-quiz-master")?.classList.contains("active")===false)return;
  clearQuizMasterStageClasses();
  const prefix=QUIZ_MASTER_STATE.guestTest?"テストプレイ / ":"";
  setTextSafe("quizMasterMessage",q.category?`${prefix}カテゴリ: ${q.category}`:(QUIZ_MASTER_STATE.guestTest?"テストプレイ中です。結果はランキングに保存されません。":""));
  QUIZ_MASTER_STATE.animating=false;
  QUIZ_MASTER_STATE.questionStartedAt=Date.now();
  updateQuizMasterFiftyButton();
  updateQuizMasterCountdown();
  QUIZ_MASTER_STATE.timer=setInterval(tickQuizMasterTimer,1000);
}
function selectQuizMasterChoice(idx){
  if(QUIZ_MASTER_STATE.answered||QUIZ_MASTER_STATE.animating)return;
  if(QUIZ_MASTER_STATE.fiftyHidden===idx)return;
  QUIZ_MASTER_STATE.selected=idx;
  document.querySelectorAll(".quiz-master-choice").forEach((b,i)=>b.classList.toggle("is-selected",i===idx));
  setTimeout(()=>confirmQuizMasterChoice(false),120);
}
function updateQuizMasterFiftyButton(){
  const btn=$("quizMasterFiftyBtn");
  if(!btn)return;
  const unavailable=QUIZ_MASTER_STATE.fiftyUsed||QUIZ_MASTER_STATE.fiftyPromptOpen||QUIZ_MASTER_STATE.answered||QUIZ_MASTER_STATE.animating||!currentQuizMasterQuestion();
  btn.disabled=unavailable;
  btn.classList.toggle("is-used",QUIZ_MASTER_STATE.fiftyUsed);
  btn.innerHTML=QUIZ_MASTER_STATE.fiftyUsed?'50:50<br><small>使用済み</small>':'50:50<br><small>1回まで</small>';
}
function askQuizMasterFiftyConfirm(){
  return new Promise(resolve=>{
    const overlay=$("quizMasterFiftyConfirmOverlay");
    if(!overlay){resolve(false);return}
    overlay.innerHTML='<div class="quiz-master-fifty-confirm-card" role="dialog" aria-modal="true" aria-label="50:50確認"><h2>50:50</h2><p>50:50は1度だけ間違えた問題を減らすことができます!<br>この問題で利用しますか?</p><div class="quiz-master-fifty-confirm-actions"><button type="button" class="primary" data-quiz-fifty="yes">はい</button><button type="button" class="secondary" data-quiz-fifty="no">いいえ</button></div></div>';
    overlay.classList.add("show");
    overlay.setAttribute("aria-hidden","false");
    const done=answer=>{
      overlay.classList.remove("show");
      overlay.setAttribute("aria-hidden","true");
      overlay.innerHTML="";
      resolve(answer);
    };
    overlay.querySelector('[data-quiz-fifty="yes"]')?.addEventListener("click",()=>done(true),{once:true});
    overlay.querySelector('[data-quiz-fifty="no"]')?.addEventListener("click",()=>done(false),{once:true});
  });
}
function resumeQuizMasterTimerAfterPrompt(){
  if(QUIZ_MASTER_STATE.answered||QUIZ_MASTER_STATE.animating||QUIZ_MASTER_STATE.remaining<=0)return;
  updateQuizMasterCountdown();
  QUIZ_MASTER_STATE.timer=setInterval(tickQuizMasterTimer,1000);
}
async function useQuizMasterFifty(){
  const q=currentQuizMasterQuestion();
  if(!q||QUIZ_MASTER_STATE.fiftyUsed||QUIZ_MASTER_STATE.fiftyPromptOpen||QUIZ_MASTER_STATE.answered||QUIZ_MASTER_STATE.animating)return;
  const answerIndex=currentQuizMasterAnswerIndex();
  const wrongIndexes=[0,1,2].filter(i=>i!==answerIndex);
  if(!wrongIndexes.length)return;
  QUIZ_MASTER_STATE.fiftyPromptOpen=true;
  QUIZ_MASTER_STATE.animating=true;
  clearQuizMasterTimer();
  updateQuizMasterFiftyButton();
  const accepted=await askQuizMasterFiftyConfirm();
  QUIZ_MASTER_STATE.fiftyPromptOpen=false;
  QUIZ_MASTER_STATE.animating=false;
  if(!accepted){
    resumeQuizMasterTimerAfterPrompt();
    updateQuizMasterFiftyButton();
    return;
  }
  if(QUIZ_MASTER_STATE.answered||QUIZ_MASTER_STATE.fiftyUsed||!currentQuizMasterQuestion())return;
  const hidden=wrongIndexes[Math.floor(Math.random()*wrongIndexes.length)];
  QUIZ_MASTER_STATE.fiftyUsed=true;
  QUIZ_MASTER_STATE.fiftyHidden=hidden;
  if(QUIZ_MASTER_STATE.selected===hidden)QUIZ_MASTER_STATE.selected=null;
  document.querySelectorAll(".quiz-master-choice").forEach((btn,i)=>{
    if(i===hidden){
      btn.classList.add("is-fifty-hidden");
      btn.disabled=true;
      btn.setAttribute("aria-disabled","true");
    }
    btn.classList.remove("is-selected");
  });
  setTextSafe("quizMasterMessage","50:50を使用しました。誤答を1つ消しました。");
  resumeQuizMasterTimerAfterPrompt();
  updateQuizMasterFiftyButton();
}
function tickQuizMasterTimer(){
  if(QUIZ_MASTER_STATE.answered)return;
  QUIZ_MASTER_STATE.remaining-=1;
  setTextSafe("quizMasterTimer",String(Math.max(0,QUIZ_MASTER_STATE.remaining)));
  updateQuizMasterCountdown();
  if(QUIZ_MASTER_STATE.remaining<=0){
    confirmQuizMasterChoice(true);
  }
}
function playQuizMasterTone(correct){
  try{
    const AudioContext=window.AudioContext||window.webkitAudioContext;
    if(!AudioContext)return;
    const ctx=new AudioContext();
    const gain=ctx.createGain();
    const osc=ctx.createOscillator();
    gain.connect(ctx.destination);
    osc.connect(gain);
    osc.type=correct?"triangle":"sawtooth";
    const now=ctx.currentTime;
    osc.frequency.setValueAtTime(correct?740:160,now);
    if(correct){
      osc.frequency.exponentialRampToValueAtTime(1108,now+.16);
      gain.gain.setValueAtTime(.0001,now);
      gain.gain.exponentialRampToValueAtTime(.22,now+.02);
      gain.gain.exponentialRampToValueAtTime(.0001,now+.35);
      osc.stop(now+.38);
    }else{
      osc.frequency.exponentialRampToValueAtTime(76,now+.28);
      gain.gain.setValueAtTime(.0001,now);
      gain.gain.exponentialRampToValueAtTime(.18,now+.02);
      gain.gain.exponentialRampToValueAtTime(.0001,now+.44);
      osc.stop(now+.48);
    }
    osc.start(now);
    setTimeout(()=>ctx.close().catch(()=>{}),650);
  }catch(e){
    console.warn("quiz tone skipped",e);
  }
}
async function confirmQuizMasterChoice(timeout=false){
  const q=currentQuizMasterQuestion();
  const choices=currentQuizMasterChoiceOrder();
  const answerIndex=currentQuizMasterAnswerIndex();
  if(!q||QUIZ_MASTER_STATE.answered)return;
  if(!timeout&&QUIZ_MASTER_STATE.selected===null)return;
  QUIZ_MASTER_STATE.answered=true;
  QUIZ_MASTER_STATE.animating=true;
  updateQuizMasterFiftyButton();
  clearQuizMasterTimer();
  const selected=timeout?-1:QUIZ_MASTER_STATE.selected;
  const correct=selected===answerIndex;
  const answerMs=Date.now()-QUIZ_MASTER_STATE.questionStartedAt;
  document.querySelectorAll(".quiz-master-choice").forEach((b,i)=>{
    b.classList.toggle("is-correct",i===answerIndex);
    b.classList.toggle("is-wrong",selected===i&&!correct);
  });
  QUIZ_MASTER_STATE.logs.push({id:q.id,level:q.level,selected,answer:answerIndex,selected_original_index:selected>=0&&choices[selected]?choices[selected].originalIndex:-1,answer_original_index:Number(q.answer),correct,answer_time_ms:answerMs});
  playQuizMasterTone(correct);
  if(correct){
    const point=quizMasterPointForLevel(q.level);
    QUIZ_MASTER_STATE.score+=point;
    setTextSafe("quizMasterScore",String(QUIZ_MASTER_STATE.score));
    setTextSafe("quizMasterMessage","正解！");
    await showQuizMasterPointBurst(point);
    setTextSafe("quizMasterMessage",q.explanation||"正解です。");
    if(q.level===QUIZ_MASTER_CHECKPOINT_LEVEL){
      const go=await showQuizMasterCheckpoint();
      if(!go){
        await playQuizMasterRoundExit();
        finishQuizMaster(false,`第${QUIZ_MASTER_CHECKPOINT_LEVEL}問クリアで終了しました。`,"checkpoint_end");
        return;
      }
      QUIZ_MASTER_STATE.challenge=true;
    }
    // 第15問以降は正解するたびに、中断（現在の点数を確保）か継続かを確認する。
    if(q.level>=QUIZ_MASTER_CHALLENGE_START_LEVEL){
      const nextQ=QUIZ_MASTER_STATE.sequence[QUIZ_MASTER_STATE.currentIndex+1];
      if(nextQ){
        const cont=await showQuizMasterContinuePrompt(QUIZ_MASTER_STATE.score,quizMasterPointForLevel(nextQ.level));
        if(!cont){
          await playQuizMasterRoundExit();
          finishQuizMaster(false,"ここで中断して点数を確保しました。","stopped");
          return;
        }
      }
    }
    QUIZ_MASTER_STATE.currentIndex+=1;
    await playQuizMasterRoundExit();
    renderQuizMasterQuestion();
  }else{
    QUIZ_MASTER_STATE.failureReview={
      question:q.question,
      selected,
      selectedText:selected>=0&&choices[selected]?(choices[selected].text||""):"時間切れ",
      answer:answerIndex,
      answerText:answerIndex>=0&&choices[answerIndex]?(choices[answerIndex].text||""):"",
      explanation:q.explanation||"",
      timeout:!!timeout
    };
    setTextSafe("quizMasterMessage",timeout?"時間切れです。":(q.explanation||"不正解です。"));
    document.body.classList.add("quiz-master-failed");
    setTimeout(()=>document.body.classList.remove("quiz-master-failed"),900);
    const finalScore=q.level>=QUIZ_MASTER_CHALLENGE_START_LEVEL?0:QUIZ_MASTER_STATE.score;
    QUIZ_MASTER_STATE.score=finalScore;
    await playQuizMasterRoundExit();
    finishQuizMaster(false,timeout?"時間切れで終了しました。":"不正解で終了しました。",q.level>=QUIZ_MASTER_CHALLENGE_START_LEVEL?"challenge_failed":(timeout?"timeout":"wrong"));
  }
}
async function playQuizMasterRoundExit(){
  const shell=quizMasterShell();
  const panel=document.querySelector(".quiz-master-question-panel");
  const choices=Array.from(document.querySelectorAll(".quiz-master-choice"));
  if(shell)shell.classList.add("quiz-master-round-exit");
  if(panel)panel.classList.add("is-exiting");
  choices.forEach((btn,idx)=>{
    btn.style.setProperty("--quiz-choice-order",String(idx));
    btn.classList.add("is-exiting");
  });
  await wait(540);
  clearQuizMasterStageClasses();
}
function showQuizMasterCheckpoint(){
  return new Promise(resolve=>{
    const overlay=$("quizMasterCheckpointOverlay");
    if(!overlay){resolve(true);return}
    const checkpointPoint=QUIZ_MASTER_STATE.score;
    overlay.innerHTML=`<div class="quiz-master-checkpoint-card" role="dialog" aria-modal="true" aria-label="チャレンジ確認"><strong>${checkpointPoint}ポイント獲得。</strong><p>第15問からは正解するたびに加算率が上がり、得点の伸びがさらに強くなります。<br>ただし失敗すると獲得点数は0点になります!<br>ここで終了しますか？</p><div class="quiz-master-checkpoint-actions"><button type="button" class="secondary" data-quiz-checkpoint="end">終了する</button><button type="button" class="primary" data-quiz-checkpoint="go">挑戦する</button></div></div>`;
    overlay.setAttribute("aria-hidden","false");
    overlay.classList.add("show");
    overlay.querySelectorAll("[data-quiz-checkpoint]").forEach(btn=>{
      btn.addEventListener("click",()=>{
        const go=btn.getAttribute("data-quiz-checkpoint")==="go";
        overlay.classList.remove("show");
        overlay.setAttribute("aria-hidden","true");
        overlay.innerHTML="";
        setTextSafe("quizMasterMessage",go?"チャレンジモードに進みます。":`第${QUIZ_MASTER_CHECKPOINT_LEVEL}問クリアで終了します。`);
        resolve(go);
      },{once:true});
    });
  });
}
function confirmQuizMasterExitWarning(){
  return new Promise(resolve=>{
    const overlay=$("quizMasterCheckpointOverlay");
    if(!overlay){resolve(true);return}
    overlay.innerHTML=`<div class="quiz-master-exit-card" role="dialog" aria-modal="true" aria-label="メニューに戻る確認">`+
      `<p class="quiz-master-exit-title">⚠ 本当にメニューに戻りますか？</p>`+
      `<ul class="quiz-master-exit-notes"><li>今のゲームは終了し、<b>獲得した点数は記録されません。</b></li><li><b>ライフを1つ消費</b>し、本日プレイできる回数が1回減ります。</li></ul>`+
      `<div class="quiz-master-exit-actions"><button type="button" class="primary" data-quiz-exit="back">ゲームに戻る</button><button type="button" class="secondary" data-quiz-exit="ok">OK</button></div>`+
      `</div>`;
    overlay.setAttribute("aria-hidden","false");
    overlay.classList.add("show");
    overlay.querySelectorAll("[data-quiz-exit]").forEach(btn=>{
      btn.addEventListener("click",()=>{
        const ok=btn.getAttribute("data-quiz-exit")==="ok";
        overlay.classList.remove("show");
        overlay.setAttribute("aria-hidden","true");
        overlay.innerHTML="";
        resolve(ok);
      },{once:true});
    });
  });
}
function showQuizMasterContinuePrompt(currentScore,nextPoint){
  return new Promise(resolve=>{
    const overlay=$("quizMasterCheckpointOverlay");
    if(!overlay){resolve(true);return}
    overlay.innerHTML=`<div class="quiz-master-continue-card" role="dialog" aria-modal="true" aria-label="中断確認">`+
      `<p class="quiz-master-continue-lead">今中断するとこちらの点数が獲得できます!</p>`+
      `<div class="quiz-master-continue-score">${escapeHtml(currentScore)} pt</div>`+
      `<p class="quiz-master-continue-next-label">次の問題で獲得できる点数は</p>`+
      `<div class="quiz-master-continue-next">+${escapeHtml(nextPoint)} pt</div>`+
      `<div class="quiz-master-continue-actions"><button type="button" class="secondary" data-quiz-continue="stop">中断する</button><button type="button" class="primary" data-quiz-continue="go">続ける</button></div>`+
      `</div>`;
    overlay.setAttribute("aria-hidden","false");
    overlay.classList.add("show");
    overlay.querySelectorAll("[data-quiz-continue]").forEach(btn=>{
      btn.addEventListener("click",()=>{
        const go=btn.getAttribute("data-quiz-continue")==="go";
        overlay.classList.remove("show");
        overlay.setAttribute("aria-hidden","true");
        overlay.innerHTML="";
        resolve(go);
      },{once:true});
    });
  });
}
function showQuizMasterPointBurst(point){
  return new Promise(resolve=>{
    const el=$("quizMasterPointBurst");
    if(!el){resolve();return}
    el.textContent=`+${point} pt`;
    el.classList.remove("show");
    void el.offsetWidth;
    el.classList.add("show");
    setTextSafe("quizMasterScore",String(point));
    setTimeout(()=>{el.classList.remove("show");resolve();},1450);
  });
}
async function finishQuizMaster(cleared=false,message="",reason=""){
  clearQuizMasterTimer();
  QUIZ_MASTER_STATE.endReason=cleared?"cleared":(reason||QUIZ_MASTER_STATE.endReason||"ended");
  // 中断（stopped）はその時点の累積スコアをそのまま獲得する（0点化しない）。
  const stopped=QUIZ_MASTER_STATE.endReason==="stopped";
  const score=QUIZ_MASTER_STATE.score;
  QUIZ_MASTER_STATE.score=score;
  show("screen-quiz-master-result");
  setTextSafe("quizMasterResultTitle",cleared?"完全制覇！":(stopped?"中断して獲得！":"チャレンジ終了"));
  setTextSafe("quizMasterFinalScore",`${score} pt`);
  renderQuizMasterResultDetail(message||`${QUIZ_MASTER_STATE.logs.length}問に挑戦しました。`,cleared);
  const rankingBox=$("quizMasterRanking");
  if(rankingBox)rankingBox.innerHTML="";
  const saveResult=await saveQuizMasterScore(cleared);
  await showQuizMasterTitleAwards(saveResult);
}
function renderQuizMasterResultDetail(message,cleared){
  const el=$("quizMasterResultDetail");
  if(!el)return;
  const review=!cleared?QUIZ_MASTER_STATE.failureReview:null;
  if(!review){
    el.textContent=message;
    return;
  }
  const answerLabel=`${String.fromCharCode(65+review.answer)}. ${review.answerText}`;
  const selectedHtml=review.timeout?"":`<div><span>選んだ答え</span><b>${escapeHtml(`${String.fromCharCode(65+review.selected)}. ${review.selectedText}`)}</b></div>`;
  el.innerHTML=`<p>${escapeHtml(message)}</p><div class="quiz-master-answer-review"><div><span>問題</span><b>${escapeHtml(review.question||"")}</b></div>${selectedHtml}<div><span>正解</span><b>${escapeHtml(answerLabel)}</b></div><p><span>理由</span>${escapeHtml(review.explanation||"この問題の解説は登録されていません。")}</p></div>`;
}
async function saveQuizMasterScore(cleared){
  if(STATE.adminMode||QUIZ_MASTER_STATE.guestTest||!STATE.loggedIn||!STATE.playerId)return null;
  try{
    const res=await fetch("api/save_quiz_master_score.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({
      player_id:STATE.playerId,
      client_token:getClientToken(),
      score:QUIZ_MASTER_STATE.score,
      reached_level:QUIZ_MASTER_STATE.logs.length,
      answered_count:QUIZ_MASTER_STATE.logs.length,
      correct_count:QUIZ_MASTER_STATE.logs.filter(l=>l.correct).length,
      cleared:!!cleared,
      challenge:!!QUIZ_MASTER_STATE.challenge,
      result_reason:QUIZ_MASTER_STATE.endReason,
      duration_sec:Math.round((Date.now()-QUIZ_MASTER_STATE.startedAt)/1000),
      question_ids:QUIZ_MASTER_STATE.logs.map(l=>l.id),
      answer_summary:QUIZ_MASTER_STATE.logs.map(l=>({id:l.id,level:l.level,selected:l.selected,answer:l.answer,selected_original_index:l.selected_original_index,answer_original_index:l.answer_original_index,correct:!!l.correct}))
    })});
    const data=await res.json().catch(()=>null);
    if(data&&data.titles)QUIZ_MASTER_STATE.titles=normalizeQuizMasterTitles(data.titles);
    return data&&data.ok?data:null;
  }catch(e){console.warn("quiz score save failed",e);return null}
}
async function showQuizMasterTitleAwards(saveResult){
  if(!saveResult||!saveResult.ok)return;
  const titles=normalizeQuizMasterTitles(saveResult.titles||QUIZ_MASTER_STATE.titles);
  const titleAfter=quizMasterTitleForScore(saveResult.total_after,titles);
  const newly=quizMasterNewTitlesBetween(saveResult.total_before,saveResult.total_after,titles);
  if(QUIZ_MASTER_TITLE_AWARD_ALWAYS_FOR_TEST&&titleAfter&&titleAfter.title&&!newly.some(row=>row.title===titleAfter.title&&row.point===titleAfter.point)){
    newly.push(titleAfter);
  }
  if(!newly.length)return;
  const el=$("quizMasterTitleBurst");
  if(!el)return;
  for(const row of newly){
    el.innerHTML=
      `<div class="qtb-backdrop"></div>`+
      `<div class="qtb-rays"></div>`+
      `<div class="qtb-kicker-wrap"><span class="qtb-kicker">新しい野球博士ランクを獲得!</span></div>`+
      `${quizMasterLevelIconHtml(row.level,'qtb-badge-icon')}`;
    el.classList.remove("show");
    void el.offsetWidth;
    el.classList.add("show");
    await wait(4800);
    el.classList.remove("show");
    await wait(250);
  }
}
async function fetchQuizMasterRanking(){
  const canPost=STATE.loggedIn&&STATE.playerId&&!STATE.adminMode;
  const options=canPost?{
    method:"POST",
    headers:{"Content-Type":"application/json"},
    body:JSON.stringify({player_id:STATE.playerId,client_token:getClientToken()})
  }:{cache:"no-store"};
  const res=await fetch("api/get_quiz_master_ranking.php",options);
  return await res.json();
}
function quizMasterResultReasonText(reason){
  if(reason==="cleared")return "完全制覇";
  if(reason==="challenge_failed")return "チャレンジ失敗";
  if(reason==="timeout")return "時間切れ";
  if(reason==="wrong")return "不正解";
  if(reason==="checkpoint_end")return `第${QUIZ_MASTER_CHECKPOINT_LEVEL}問で終了`;
  if(reason==="stopped")return "途中で中断";
  return "終了";
}
function renderQuizMasterRanking(box,data,mode="result"){
  const rows=Array.isArray(data&&data.ranking)?data.ranking:[];
  const myBest=data&&data.my_best?data.my_best:null;
  const myTotal=data&&data.my_total?data.my_total:null;
  const summary=data&&data.summary?data.summary:{};
  const topRows=rows.slice(0,20);
  const summaryHtml=mode==="page"?`<div class="quiz-master-ranking-summary"><span>参加者 <b>${escapeHtml(summary.total_players||0)}</b></span><span>プレイ数 <b>${escapeHtml(summary.total_plays||0)}</b></span><span>完全制覇 <b>${escapeHtml(summary.cleared_count||0)}</b></span></div>`:"";
  const renderRow=(r,extraClass)=>{
    const score=Number(r.total_score||r.score||0);
    const title=r.title_info||quizMasterTitleForScore(score,data&&data.titles);
    const cls=[(r.player_id===STATE.playerId?"me":""),extraClass||""].filter(Boolean).join(" ");
    return `<li class="${cls}"><span>${escapeHtml(r.rank)}位</span>${quizMasterLevelIconHtml(title.level,'qm-icon-rank')}<div class="rank-plays"><small>プレイ数 ${escapeHtml(r.plays||0)}回</small><small>完全制覇 ${escapeHtml(r.cleared_count||0)}回</small></div><div class="rank-name"><b>${escapeHtml(r.player_id)}</b><em>${escapeHtml(score)} pt</em></div></li>`;
  };
  let listItems=topRows.map(r=>renderRow(r)).join("");
  // 自分がトップ20圏外なら、最下部に同じデザインで自分のカードを21個目として追加する。
  const inTop=!!STATE.playerId&&topRows.some(r=>r.player_id===STATE.playerId);
  if(!inTop&&myTotal&&STATE.playerId){
    listItems+=renderRow(myTotal,"rank-outside");
  }
  const rankingHtml=listItems?`<ol>${listItems}</ol>`:'<p>まだランキングはありません。</p>';
  box.innerHTML=`<div class="quiz-master-ranking-board"><div class="quiz-master-ranking-title">野球博士総合点ランキング TOP20</div>${summaryHtml}${rankingHtml}</div>`;
}
async function loadQuizMasterRanking(targetId="quizMasterRanking",mode="result"){
  const box=$(targetId);if(!box)return;
  box.innerHTML='<div class="quiz-master-ranking-title">ランキングを読み込み中...</div>';
  try{
    renderQuizMasterRanking(box,await fetchQuizMasterRanking(),mode);
  }catch(e){
    box.innerHTML='<div class="quiz-master-ranking-title">ランキングを読み込めませんでした。</div>';
  }
}
async function openQuizMasterRanking(){
  show("screen-quiz-master-ranking");
  await loadQuizMasterRanking("quizMasterRankingPage","page");
}
function openQuizMasterMenu(){
  show("screen-quiz-master-menu");
}
async function showQuizMasterRanks(){
  show("screen-quiz-master-ranks");
  await renderQuizMasterRanks();
}
async function renderQuizMasterRanks(){
  try{
    const data=await fetchQuizMasterRanking();
    const titles=normalizeQuizMasterTitles(data&&data.titles?data.titles:QUIZ_MASTER_STATE.titles);
    const myTotal=data&&data.my_total?data.my_total:null;
    const myScore=Number((myTotal&&(myTotal.total_score||myTotal.score))||(STATE.loggedIn?(STATE.quizMasterTotal||{}).total_score:0)||0);
    const myTitle=(STATE.loggedIn||myTotal)?quizMasterTitleForScore(myScore,titles):null;
    const box=$("quizMasterRanksContainer");
    if(!box)return;
    const rankUserCounts=data&&data.rank_user_counts?data.rank_user_counts:{};
    const ranksHtml=titles.map((title,idx)=>{
      const isCurrentRank=STATE.loggedIn&&myTitle&&myTitle.level===title.level;
      const userCount=rankUserCounts[title.level]||rankUserCounts[idx+1]||0;
      return `<div class="rank-item${isCurrentRank?' current-rank':''}"><div class="rank-icon-wrapper">${quizMasterLevelIconHtml(title.level,'rank-icon')}</div><div class="rank-details"><div class="rank-name">${escapeHtml(title.title)}</div><div class="rank-points">必要点数: ${escapeHtml(title.point)} pt</div><div class="rank-users">到達人数: ${escapeHtml(userCount)}人</div></div></div>`;
    }).join("");
    box.innerHTML=`<div class="ranks-grid">${ranksHtml}</div>`;
  }catch(e){
    console.error("ランク情報の読み込みエラー:",e);
    const box=$("quizMasterRanksContainer");
    if(box)box.innerHTML='<p>ランク情報を読み込めませんでした。</p>';
  }
}

function normalizeSimilarText(v){
  return String(v||"").replace(/\s+/g,"").trim();
}

function attackSimilarKey(q){
  if(!q || q.type!=="attack")return "";
  const txt=normalizeSimilarText(`${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""}`);
  let category="";
  if(/ワイルドピッチ|暴投|捕逸|パスボール|捕手の後ろ|キャッチャーの後ろ/.test(txt)) category="battery_miss";
  else if(/盗塁|スタート|走った/.test(txt)) category="steal";
  else if(/ゴロ/.test(txt)) category="grounder";
  else if(/フライ|ライナー/.test(txt)) category="fly";
  else if(/バント|スクイズ/.test(txt)) category="bunt";
  else category=normalizeSimilarText(q.theme||q.ball_tag||q.id||"");
  return `${q.stage||""}:${category}`;
}



// v802: 出題候補をランダム抽出した後、同じ半イニング内でアウトカウント順に安定並び替えする。
// 数値outsは該当アウトのみ、outs_scope:"common" は全アウト対応。
// outs未設定かつouts_scopeなしの未監査問題はflexible候補にしない。
function getQuestionOutsSortKeyForDisplay(question){
  if(!question)return 99;
  const n=Number(question.outs);
  if(Number.isInteger(n)&&n>=0&&n<=2)return n;
  return 99;
}
function sortQuestionsByOutsStableForDisplay(questions){
  if(!Array.isArray(questions))return questions;
  return questions
    .map((question,originalIndex)=>({question,originalIndex,outsSortKey:getQuestionOutsSortKeyForDisplay(question)}))
    .sort((a,b)=>{
      if(a.outsSortKey!==b.outsSortKey)return a.outsSortKey-b.outsSortKey;
      return a.originalIndex-b.originalIndex;
    })
    .map(item=>item.question);
}
function sortQuestionSequenceByOutsWithinHalfInnings(sequence){
  if(!Array.isArray(sequence))return sequence;
  const result=[];
  let group=[];
  let groupKey=null;
  function flush(){
    if(group.length){
      result.push(...sortQuestionsByOutsStableForDisplay(group));
      group=[];
    }
  }
  sequence.forEach((question)=>{
    const key=`${question&&question.inning!==undefined?question.inning:""}|${question&&question.requiredType!==undefined?question.requiredType:(question&&question.type)||""}`;
    if(groupKey!==null&&key!==groupKey)flush();
    groupKey=key;
    group.push(question);
  });
  flush();
  return result;
}

function makeSequence(){
  const grade=Number($("grade").value);
  if(grade<=2){return makeBasicSequence()}
  const seq=[];
  const usedThemesBySide={attack:[],defense:[]};
  const usedAttackSimilarKeysByInning={};
  const usedSlotKeys=new Set();
  const usedQuestionIds=new Set(); // 同一プレイ内の同一問題ID重複を原則禁止
  const recentIds=loadRecentQuestionIds(STATE.playerId);
  let attackIndex=0;
  let defenseIndex=0;
  for(let i=0;i<18;i++){
    const rawSlot=INNING_SLOTS[i];
    const typ0=rawSlot[3];
    const slot=pickDynamicSlot(typ0,rawSlot,typ0==="attack"?attackIndex++:defenseIndex++,usedSlotKeys,usedQuestionIds);
    const [inning,outs,stage,typ]=slot;
    const poolBase=questionPoolForSlot(typ,stage,outs,grade,STATE.position,usedQuestionIds);
    const pool=poolBase.length?poolBase:questionPoolForSlot(typ,stage,outs,grade,STATE.position,null);
    if(!pool.length){
      throw new Error(`問題データが不足しています: ${typ}/${stage}/outs=${outs}/grade<=${grade+1}`);
    }
    const attackUsedKeys=typ==="attack"?(usedAttackSimilarKeysByInning[inning]||(usedAttackSimilarKeysByInning[inning]=new Set())):null;
    const selected=chooseQuestionFromPool(pool,typ,usedThemesBySide,attackUsedKeys,recentIds,usedQuestionIds);
    let q={...selected};
    q.inning=inning;
    q.outs=outs;
    q.stage=stage;
    q.requiredType=typ;
    seq.push(q);
    if(q.id){
      usedQuestionIds.add(q.id);
      recentIds.push(q.id);
    }
    usedThemesBySide[typ].push(q.theme);
    if(typ==="attack" && attackUsedKeys)attackUsedKeys.add(attackSimilarKey(q));
  }
  saveRecentQuestionIds(recentIds,STATE.playerId);
  return sortQuestionSequenceByOutsWithinHalfInnings(seq);
}

function makeBasicSequence(){
  const recentIds=loadRecentQuestionIds(STATE.playerId);
  const base=STATE.questions.filter(q=>q.type==="basic");
  if(base.length<10){
    throw new Error("基本動作問題データが不足しています");
  }
  const selected=[];
  const used=new Set();
  for(let i=0;i<10;i++){
    const pool=base.filter(q=>!used.has(q.id));
    const q=weightedPick(pool,x=>questionHistoryWeight(x,recentIds,used))||pool[0];
    if(!q)break;
    selected.push(q);
    if(q.id){used.add(q.id);recentIds.push(q.id);}
  }
  saveRecentQuestionIds(recentIds,STATE.playerId);
  return selected.map((q,i)=>({...q,inning:`基本問題 ${i+1}`,outs:null,stage:"BASIC",requiredType:"basic"}));
}


function showTransitionTitle(text, done){
  const el = document.getElementById("transitionOverlay");
  if(!el){ done(); return; }
  el.textContent = text;
  el.classList.add("show");
  setTimeout(()=>{ el.classList.remove("show"); done(); }, 900);
}
function sideStartTitle(q, prevQ=null){
  if(!q || q.type==="basic") return "";
  // v289: 攻守開始アニメーションは「半イニングの最初」だけ表示する。
  // これまでは outs===0 の問題ごとに表示していたため、
  // ノーアウト二塁→ノーアウト三塁のような連続出題で「攻撃開始」が連続表示されていた。
  if(prevQ && prevQ.type===q.type && prevQ.inning===q.inning) return "";
  return q.type==="attack" ? "攻撃開始" : "守備開始";
}



function isBasicGrade(){
  const gradeSel=$("grade");
  return gradeSel&&Number(gradeSel.value)<=2;
}
function defaultProgressForPosition(){
  return {completed_grades:[],max_unlocked_grade:3};
}
function progressForPosition(pos){
  return (STATE.progress&&STATE.progress[pos])?STATE.progress[pos]:defaultProgressForPosition();
}
function maxUnlockedGrade(pos){
  const p=progressForPosition(pos);
  const n=Number(p.max_unlocked_grade||3);
  return Math.max(3,Math.min(6,n));
}
function updateGradeOptions(){
  const gradeSel=$("grade");
  const posSel=$("position");
  if(!gradeSel||!posSel)return;
  const basic=isBasicGrade();
  const suppressTopGameStatus=isLikelyMobileOrTablet()&&!isPwaStandalone();

  // 通常ブラウザのPWAインストール案内画面では、ゲーム開始前の学年説明を表示しない。
  // updateRegistrationAvailability() が game-start-control を非表示にした後、
  // updateGradeOptions() が gradeLockStatus だけ再表示してしまうのを防ぐ。
  if(suppressTopGameStatus){
    const status=$("gradeLockStatus");
    if(status){
      status.textContent="";
      status.style.display="none";
    }
  }

  // 管理者用モードでも、3年生以下は基本動作モードのため守備位置を選択不可にする
  if(STATE.adminMode){
    posSel.disabled=basic;
    posSel.closest("label")?.classList.toggle("position-disabled",basic);
    [...gradeSel.options].forEach(opt=>{
      const g=Number(opt.value);
      opt.disabled=false;
      opt.textContent=gradeMenuLabel(g);
    });
    const status=$("gradeLockStatus");
    if(status){
      status.textContent="";
      status.style.display="none";
    }
    return;
  }

  posSel.disabled=basic;
  posSel.closest("label")?.classList.toggle("position-disabled",basic);
  const pos=posSel.value||STATE.position||"SS";
  const maxGrade=maxUnlockedGrade(pos);
  [...gradeSel.options].forEach(opt=>{
    const g=Number(opt.value);
    if(g<=2){
      opt.disabled=false;
      opt.textContent=gradeMenuLabel(g);
    }else{
      opt.disabled=g>maxGrade;
      opt.textContent=gradeMenuLabel(g,g>maxGrade);
    }
  });
  if(!basic&&Number(gradeSel.value)>maxGrade)gradeSel.value=String(maxGrade);
  const status=$("gradeLockStatus");
  if(status){
    if(suppressTopGameStatus){
      status.textContent="";
      status.style.display="none";
      return;
    }
    status.style.display="";
    if(basic){
      status.textContent="3年生以下は基本動作モードです。守備位置は選択せず、ランキング対象外です。";
    }else{
      const label=(STATE.config&&STATE.config.positions&&STATE.config.positions[pos])?STATE.config.positions[pos]:pos;
      const completed=(progressForPosition(pos).completed_grades||[]).map(Number).sort((a,b)=>a-b);
      const completedText=completed.length?`クリア済み：${completed.map(g=>g+"年生").join("、")}`:"まだクリアなし";
      status.textContent=`${label}は現在${maxGrade}年生まで選択できます。${completedText}（40点以上でクリア）`;
    }
  }
}
async function loadPlayerProgress(pid){
  if(!pid)return;
  try{
    const res=await fetch("api/get_progress.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({player_id:pid})});
    const data=await res.json();
    if(res.ok&&data&&data.ok&&data.progress){
      STATE.progress=data.progress;
    }else{
      STATE.progress={};
    }
  }catch(e){
    console.warn("progress load failed",e);
    STATE.progress={};
  }
  updateGradeOptions();
}
function markGradeCompleted(position,grade){
  const g=Number(grade);
  if(g<=2)return null;
  if(!position||!GRADE_STEPS.includes(g))return null;
  if(!STATE.progress[position])STATE.progress[position]=defaultProgressForPosition();
  const p=STATE.progress[position];
  const before=maxUnlockedGrade(position);
  const completed=new Set((p.completed_grades||[]).map(Number));
  completed.add(g);
  p.completed_grades=[...completed].sort((a,b)=>a-b);
  const maxCompleted=Math.max(...p.completed_grades,0);
  p.max_unlocked_grade=Math.min(6,Math.max(3,maxCompleted+1));
  updateGradeOptions();
  const after=maxUnlockedGrade(position);
  if(after>before){
    return {position,unlockedGrade:after};
  }
  return null;
}
function showUnlockAnimation(unlock){
  if(!unlock||!unlock.unlockedGrade)return;
  const label=(STATE.config&&STATE.config.positions&&STATE.config.positions[unlock.position])?STATE.config.positions[unlock.position]:unlock.position;
  let el=document.getElementById("unlockOverlay");
  if(!el){
    el=document.createElement("div");
    el.id="unlockOverlay";
    el.className="unlock-overlay";
    document.body.appendChild(el);
  }
  el.innerHTML=`<div class="unlock-box"><div class="unlock-main">問題開放!</div><div class="unlock-sub">${escapeHtml(label)} ${escapeHtml(unlock.unlockedGrade)}年生</div></div>`;
  el.classList.remove("show");
  void el.offsetWidth;
  el.classList.add("show");
  setTimeout(()=>el.classList.remove("show"),2200);
}
function isSelectedGradeUnlocked(){
  if(STATE.adminMode)return true;
  if(isBasicGrade())return true;
  const pos=$("position").value;
  const grade=Number($("grade").value);
  const maxGrade=maxUnlockedGrade(pos);
  if(grade>maxGrade){
    $("grade").value=String(maxGrade);
    updateGradeOptions();
    alert(`${STATE.config.positions[pos]}はまだ${grade}年生を選択できません。${maxGrade}年生をクリアすると次の学年が選べます。`);
    return false;
  }
  return true;
}


function getCookieValue(name){
  const key=`${encodeURIComponent(name)}=`;
  return document.cookie.split(";").map(v=>v.trim()).find(v=>v.startsWith(key))?.slice(key.length)||"";
}
function setCookieValue(name,value,maxAgeDays=400){
  const maxAge=Math.max(1,Number(maxAgeDays)||400)*24*60*60;
  const secure=location.protocol==="https:"?"; Secure":"";
  document.cookie=`${encodeURIComponent(name)}=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; SameSite=Lax${secure}`;
}
function deleteCookieValue(name){
  const secure=location.protocol==="https:"?"; Secure":"";
  document.cookie=`${encodeURIComponent(name)}=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
}
function generateClientToken(){
  if(window.crypto&&crypto.getRandomValues){
    const a=new Uint32Array(4);
    crypto.getRandomValues(a);
    return [...a].map(x=>x.toString(36)).join("-");
  }
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}
function getClientToken(){
  const storageKey="baseballClientToken";
  const cookieKey="baseballClientToken";
  let token="";
  try{token=localStorage.getItem(storageKey)||"";}catch(e){token="";}
  const cookieToken=decodeURIComponent(getCookieValue(cookieKey)||"");
  if(!token&&cookieToken){
    token=cookieToken;
    try{localStorage.setItem(storageKey,token);}catch(e){}
  }
  if(!token){
    token=generateClientToken();
    try{localStorage.setItem(storageKey,token);}catch(e){}
  }
  if(!cookieToken||cookieToken!==token){
    setCookieValue(cookieKey,token,400);
  }
  return token;
}
async function registerPlayerId(pid, options={}){
  const silent=!!options.silent;
  try{
    const res=await fetch("api/register_player.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({player_id:pid,client_token:getClientToken()})});
    const data=await res.json().catch(()=>({ok:false,error:"invalid response"}));
    if(res.status===409||data.error==="duplicate_player_id"){
      if(!silent)alert(data.message||"このプレイヤーIDは既に別の端末で使われています。別のプレイヤーIDを入力してください。");
      return {ok:false,status:res.status,error:data.error||"duplicate_player_id",message:data.message||"このプレイヤーIDは既に別の端末で使われています。"};
    }
    if(!res.ok||!data.ok){
      const message=data.message||"プレイヤーIDの確認に失敗しました。時間をおいて再度お試しください。";
      if(!silent)alert(message);
      return {ok:false,status:res.status,error:data.error||"register_failed",message};
    }
    return {ok:true,status:res.status,data};
  }catch(e){
    console.warn("player id register failed",e);
    const message="プレイヤーIDの確認に失敗しました。通信環境を確認してください。";
    if(!silent)alert(message);
    return {ok:false,status:0,error:"network_error",message};
  }
}

async function ensureCurrentPlayerVerifiedForFeature(){
  if(!STATE.playerId)return {ok:false,message:"先にプレイヤーIDでログインしてください。"};
  const result=await registerPlayerId(STATE.playerId,{silent:true});
  if(result&&result.ok)return result;
  if(result&&result.error==="duplicate_player_id"){
    return {ok:false,error:result.error,message:"現在のプレイヤーIDは、別の端末情報で登録されています。トップ画面で一度ログアウトして、別のプレイヤーIDでログインするか、管理者に登録状態の確認を依頼してください。"};
  }
  return {ok:false,error:(result&&result.error)||"verify_failed",message:(result&&result.message)||"プレイヤーIDの確認に失敗しました。トップ画面で一度ログアウトして、同じプレイヤーIDで再ログインしてからお試しください。"};
}

async function setLoggedInPlayer(pid){STATE.playerId=pid;STATE.loggedIn=true;localStorage.setItem("baseballPlayerId",pid);$("playerId").value=pid;updateLoginUI();loadPlayerProgress(pid);await refreshFeatureFlags(pid);loadOwnServerMistakes(pid)}

function validatePlayerIdFormat(pid){
  const id=String(pid||"").trim();
  if(id.length<4)return "プレイヤーIDは4文字以上で入力してください。";
  if(id.length>20)return "プレイヤーIDは20文字以内で入力してください。";
  if(!/^[A-Za-z0-9_-]+$/.test(id))return "プレイヤーIDに使える文字は、半角英数字・ハイフン・アンダーバーのみです。";
  return validatePlayerIdContent(id);
}
function validatePlayerIdContent(pid){
  const id=String(pid||"").trim().toLowerCase();
  const compact=id.replace(/[-_]/g,"");
  const officialWords=[
    "admin","administrator","root","system","support","official","owner","operator","staff",
    "master","manager","moderator","mod","運営","公式","管理者","サポート"
  ];
  if(officialWords.some(w=>id.includes(w)||compact.includes(w))){
    return "運営者や公式と誤認される表現はプレイヤーIDに使用できません。";
  }

  const inappropriateWords=[
    "sex","sexy","porn","porno","fuck","fxxk","shit","bitch","asshole","kill","die","death",
    "rape","nazi","terror","weapon","drug","suicide","idiot","stupid","baka","aho","kuso"
  ];
  if(inappropriateWords.some(w=>id.includes(w)||compact.includes(w))){
    return "不適切な言葉を含むプレイヤーIDは使用できません。";
  }

  if(/\d{10,}/.test(compact)){
    return "電話番号などの個人情報に見える数字列はプレイヤーIDに使用できません。";
  }
  if(/(?:\d[ -_]*?){13,19}/.test(id)){
    return "クレジットカード番号などの個人情報に見える数字列はプレイヤーIDに使用できません。";
  }
  if(id.includes("@") || /https?|www/.test(id)){
    return "メールアドレスやURLに見える表現はプレイヤーIDに使用できません。";
  }

  const brandWords=[
    "nintendo","pokemon","mario","pikachu","disney","mickey","marvel","sony","playstation",
    "apple","google","youtube","line","tiktok","instagram","twitter","xjapan","dragonball",
    "onepiece","naruto","doraemon","anpanman","kimetsu","totoro","ghibli"
  ];
  if(brandWords.some(w=>id.includes(w)||compact.includes(w))){
    return "企業名・商標・有名キャラクター名にあたる表現はプレイヤーIDに使用できません。";
  }
  return "";
}

function currentInputPlayerId(){return sanitizeId($("playerId").value)}
async function loginWithCurrentId(){
  if(!canRegisterPlayerOnThisEnvironment()){
    updateRegistrationAvailability();
    alert(isLineInAppBrowser()?"LINE内ブラウザではプレイヤーID登録できません。Safariで開いてホーム画面に追加し、ホーム画面アプリから登録してください。":"スマホ・iPadでは、ホーム画面に追加したアプリから開いた時だけプレイヤーID登録できます。");
    return false;
  }
  const pid=currentInputPlayerId();
  const idError=validatePlayerIdFormat(pid);
  if(idError){alert(idError);return false}
  const reg=await registerPlayerId(pid);if(!reg||!reg.ok)return false;await setLoggedInPlayer(pid);trackAccessEvent("login","success");return true}

function canChangePlayerIdOnThisEnvironment(){
  return canRegisterPlayerOnThisEnvironment();
}
async function changePlayerId(){
  const oldId=STATE.loggedIn&&STATE.playerId?STATE.playerId:"";
  const newId=sanitizeId(($("newPlayerIdInput")&&$("newPlayerIdInput").value)||"");
  const msg=$("changePlayerIdMessage");
  const setMsg=(text,ok=false)=>{if(msg){msg.textContent=text;msg.className=ok?"request-message ok":"request-message err";}};
  if(!oldId){setMsg("ログイン中のIDがありません。先にログインしてください。");return;}
  if(!canChangePlayerIdOnThisEnvironment()){
    setMsg(isLineInAppBrowser()?"LINE内ブラウザではプレイヤーID変更できません。ホーム画面アプリから開いてください。":"スマホ・iPadでは、ホーム画面に追加したアプリから開いた時だけプレイヤーID変更できます。");
    return;
  }
  const idError=validatePlayerIdFormat(newId);
  if(idError){setMsg(idError);return;}
  if(newId.toLowerCase()===oldId.toLowerCase()){setMsg("現在と同じプレイヤーIDです。別のプレイヤーIDを入力してください。");return;}
  if(!confirm(`プレイヤーIDを「${oldId}」から「${newId}」に変更します。\n成績・ランキング・間違いプレイ記録などを新しいプレイヤーIDへ引き継ぎます。よろしいですか？`))return;
  const btn=$("changePlayerIdBtn");
  if(btn)btn.disabled=true;
  setMsg("プレイヤーIDを変更中です...");
  try{
    const res=await fetch("api/change_player_id.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({old_player_id:oldId,new_player_id:newId,client_token:getClientToken()})});
    const data=await res.json().catch(()=>({ok:false,message:"サーバー応答を読み込めませんでした。"}));
    if(!res.ok||!data.ok){
      setMsg(data.message||"プレイヤーIDの変更に失敗しました。");
      return;
    }
    STATE.playerId=newId;
    STATE.loggedIn=true;
    localStorage.setItem("baseballPlayerId",newId);
    $("playerId").value=newId;
    if($("newPlayerIdInput"))$("newPlayerIdInput").value="";
    updateLoginUI();
    loadMistakeReviewSetting(STATE.playerId);
    updateGradeOptions();
    await refreshFeatureFlags(newId);
    loadPlayerProgress(newId);
    loadOwnServerMistakes(newId);
    trackAccessEvent("change_player_id","success");
    setMsg(data.message||"プレイヤーIDを変更しました。",true);
  }catch(e){
    console.warn("change player id failed",e);
    setMsg("通信エラーでプレイヤーIDを変更できませんでした。");
  }finally{
    if(btn)btn.disabled=false;
  }
}


let cachedPushPublicKey="";
async function getPushPublicKey(){
  if(cachedPushPublicKey)return cachedPushPublicKey;
  const res=await fetch("api/get_push_public_key.php",{cache:"no-store"});
  const data=await res.json().catch(()=>({ok:false,message:"invalid json"}));
  if(!res.ok||!data.ok||!data.publicKey){
    throw new Error((data&&data.message)||"プッシュ通知用の公開鍵を取得できませんでした。");
  }
  cachedPushPublicKey=String(data.publicKey);
  return cachedPushPublicKey;
}

function urlBase64ToUint8Array(base64String){
  const padding="=".repeat((4-base64String.length%4)%4);
  const base64=(base64String+padding).replace(/-/g,"+").replace(/_/g,"/");
  const raw=atob(base64);
  const output=new Uint8Array(raw.length);
  for(let i=0;i<raw.length;i++)output[i]=raw.charCodeAt(i);
  return output;
}
function isPushSupported(){
  return "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
}
function isPushEnvironmentAvailable(){
  const isLocalhost = location.hostname === "localhost" || location.hostname === "127.0.0.1";
  const isSecure = location.protocol === "https:" || isLocalhost;
  return isSecure && isPushSupported();
}
function updatePushSectionAvailability(){
  const section=$("pushNotificationSettingsSection");
  if(!section)return;
  // PCでもWeb Push対応ブラウザでは利用可能。非対応環境だけ非表示にする。
  section.style.display=isPushEnvironmentAvailable()?"":"none";
}


function updatePushStatusText(text){
  const el=$("pushNotificationStatus");
  if(el)el.textContent=text;
}
function setPushMessage(text,ok=false){
  const el=$("pushNotificationMessage");
  if(!el){
    if(text)alert(String(text));
    return;
  }
  el.textContent=text||"";
  el.className=ok?"settings-message success":"settings-message";
  el.style.display=text?"block":"";
}
async function refreshPushStatus(){
  updatePushSectionAvailability();
  const guide=$("pushFirstGuide");
  if(!isPushEnvironmentAvailable()){
    updatePushStatusText("非対応");
    if(guide)guide.style.display="block";
    return;
  }
  if(Notification.permission==="denied"){
    updatePushStatusText("拒否されています");
    if(guide)guide.style.display="block";
    return;
  }
  try{
    const reg=await navigator.serviceWorker.ready;
    const sub=await reg.pushManager.getSubscription();
    updatePushStatusText(sub?"通知オン":(Notification.permission==="granted"?"許可済み・未購読":"未設定"));
    if(guide)guide.style.display=sub?"none":"block";
  }catch(e){
    updatePushStatusText("確認できません");
    if(guide)guide.style.display="block";
  }
}
function pushEnvironmentDiagnostic(){
  const items=[];
  items.push(`HTTPS:${location.protocol==="https:"||location.hostname==="localhost"?"OK":"NG"}`);
  items.push(`ServiceWorker:${"serviceWorker" in navigator?"OK":"NG"}`);
  items.push(`PushManager:${"PushManager" in window?"OK":"NG"}`);
  items.push(`Notification:${"Notification" in window?"OK":"NG"}`);
  if("Notification" in window)items.push(`通知許可:${Notification.permission}`);
  items.push(`ログイン:${STATE.loggedIn&&STATE.playerId?"OK":"NG"}`);
  return items.join(" / ");
}
async function enablePushNotifications(){
  setPushMessage("通知設定を確認しています。",true);
  updatePushSectionAvailability();

  if(!STATE.loggedIn || !STATE.playerId){
    setPushMessage("先にプレイヤーIDでログインしてください。 "+pushEnvironmentDiagnostic());
    return;
  }
  if(!isPushEnvironmentAvailable()){
    setPushMessage("この端末またはブラウザはプッシュ通知に対応していません。 "+pushEnvironmentDiagnostic());
    updatePushStatusText("非対応");
    updatePushSectionAvailability();
    return;
  }
  if(isLikelyMobileOrTablet()&&!isPwaStandalone()){
    setPushMessage("スマホ・iPadではホーム画面アプリから開いている時のみ通知設定できます。PCのChromeではこの制限はありません。");
    return;
  }
  if(Notification.permission==="denied"){
    updatePushStatusText("拒否されています");
    setPushMessage("ブラウザ側で通知が拒否されています。Chromeのサイト設定から通知を許可に変更してください。 "+pushEnvironmentDiagnostic());
    return;
  }

  try{
    const permission=await Notification.requestPermission();
    if(permission!=="granted"){
      updatePushStatusText(permission==="denied"?"拒否されています":"未設定");
      setPushMessage("通知が許可されませんでした。 "+pushEnvironmentDiagnostic());
      return;
    }

    let reg=await navigator.serviceWorker.ready;
    if(!reg && navigator.serviceWorker.register){
      await navigator.serviceWorker.register("service-worker.js");
      reg=await navigator.serviceWorker.ready;
    }

    let sub=await reg.pushManager.getSubscription();
    if(!sub){
      const publicKey=await getPushPublicKey();
      sub=await reg.pushManager.subscribe({
        userVisibleOnly:true,
        applicationServerKey:urlBase64ToUint8Array(publicKey)
      });
    }

    const res=await fetch("api/save_push_subscription.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({
        player_id:STATE.playerId,
        subscription:sub,
        client_token:getClientToken(),
        user_agent:navigator.userAgent||""
      })
    });
    const data=await res.json().catch(()=>({ok:false,error:"invalid json"}));
    if(!res.ok||!data.ok)throw new Error(data.error||data.message||"save failed");

    updatePushStatusText("通知オン");
    const guide=$("pushFirstGuide");if(guide)guide.style.display="none";
    setPushMessage("この端末でプッシュ通知を受け取れるようにしました。",true);
  }catch(e){
    console.warn("push enable failed",e);
    updatePushStatusText("未設定");
    setPushMessage("通知設定に失敗しました："+(e&&e.message?e.message:"原因不明")+" "+pushEnvironmentDiagnostic());
  }
}


function handleInitialOpenAction(){
  try{
    const params=new URLSearchParams(location.search||"");
    const open=params.get("open");
    if(!open)return;
    const cleanUrl=location.pathname+(location.hash||"");
    const doOpen=()=>{
      if(open==="ranking"){
        if(STATE.loggedIn&&STATE.playerId){openRanking();}
        else{
          const status=$("loginStatus");
          if(status)status.textContent="ランキングを見るには、先にプレイヤーIDでログインしてください。";
        }
      }else if(open==="mypage"){
        if(STATE.loggedIn&&STATE.playerId){openMyPage();}
        else{
          const status=$("loginStatus");
          if(status)status.textContent="マイページを見るには、先にプレイヤーIDでログインしてください。";
        }
      }else if(open==="notices"){
        openNotices();
      }
      if(history&&history.replaceState)history.replaceState(null,"",cleanUrl);
    };
    setTimeout(doOpen,700);
  }catch(e){
    console.warn("initial open action failed",e);
  }
}

function logoutPlayer(){STATE.playerId="";STATE.loggedIn=false;STATE.progress={};STATE.featureFlags={};STATE.featureStatus=null;STATE.mistakeReviewEnabled=false;STATE.adminMode=false;localStorage.setItem("mistakeReviewEnabled","0");localStorage.setItem("adminMode","0");localStorage.removeItem("baseballPlayerId");$("playerId").value="";updateLoginUI();updateGradeOptions();updateAdminModeUI();show("screen-title")}
function updateLoginUI(){const pid=STATE.loggedIn&&STATE.playerId?STATE.playerId:"";const idForChange=$("currentPlayerIdForChange");if(idForChange)idForChange.textContent=pid||"未ログイン";const inputId=currentInputPlayerId?currentInputPlayerId():"";const status=$("loginStatus");if(status){if(STATE.adminMode){status.textContent=pid?`プレイヤーID：${pid}（管理者用モード中：制限時間なし・ランキング反映なし）`:"管理者用モード中：制限時間なし・ランキング反映なし";}else{status.textContent=pid?`プレイヤーID：${pid}`:"野球博士チャレンジは、プレイヤーIDでログインし、オプション機能を解放した方のみ利用できます。";}}const inputArea=$("playerIdInputArea");if(inputArea)inputArea.style.display=pid?"none":"";const topRule=$("topPlayerIdRuleAccordion");if(topRule)topRule.style.display=pid?"none":"";const guide=$("guestGuide");if(guide)guide.style.display=(!STATE.adminMode&&!pid&&!inputId)?"block":"none";const login=$("loginBtn");if(login)login.style.display=pid?"none":"inline-block";const my=$("myPageBtn");if(my){my.disabled=!pid;my.style.display=pid?"inline-block":"none"}const ranking=$("rankingBtn");if(ranking){ranking.disabled=!pid;ranking.style.display=pid?"inline-block":"none"}const out=$("logoutBtn");if(out)out.style.display=pid?"inline-block":"none";const start=$("startBtn");if(start){start.style.display=pid?"block":"none";start.disabled=!pid}const canShowQuizMaster=!!pid&&hasQuizMasterFeatureAccess();const quiz=$("quizMasterBtn");if(quiz){quiz.style.display=canShowQuizMaster?"block":"none";quiz.disabled=!canShowQuizMaster}const quizRank=$("quizMasterRankingBtn");if(quizRank){quizRank.style.display=canShowQuizMaster?"block":"none";quizRank.disabled=!canShowQuizMaster}updateQuizMasterDailyUI();updateIssueKeyActions();updateRequestMenuVisibility();updatePwaInstallGuide()}

function latestPlayHtml(value){
  return escapeHtml(fmtDate(value));
}

function fmtScore(v){const n=Number(v);return Number.isFinite(n)?String(n):"0"}
function fmtDate(s){return s||"-"}
function fmtTimeSeconds(v){const n=Number(v);return Number.isFinite(n)?`${n.toFixed(2)}秒`:"-"}
function rankStars(rank){const r=Number(rank);return r===1?"<span class=\"rank-stars\" aria-label=\"星3つ\">★★★</span>":r===2?"<span class=\"rank-stars\" aria-label=\"星2つ\">★★</span>":r===3?"<span class=\"rank-stars\" aria-label=\"星1つ\">★</span>":""}

function collectMyTopRanks(rankingData,playerId){
  if(!rankingData||!playerId)return [];
  const items=[];
  const add=(label,row)=>{
    if(!row)return;
    const rank=Number(row.rank);
    if(rank>=1&&rank<=3)items.push({label,rank});
  };
  const overall=Array.isArray(rankingData.overall_ranking)?rankingData.overall_ranking:(Array.isArray(rankingData.ranking)?rankingData.ranking:[]);
  add("総合ランキング",overall.find(r=>r.player_id===playerId));
  const posRankings=rankingData.position_rankings||{};
  Object.values(posRankings).forEach(block=>{
    const posLabel=block.position_label||block.position||"守備位置";
    const main=Array.isArray(block.ranking)?block.ranking:[];
    add(`${posLabel}ランキング`,main.find(r=>r.player_id===playerId));
    const gradeRankings=block.grade_rankings||{};
    Object.values(gradeRankings).forEach(gb=>{
      const gradeLabel=gb.grade_label||((gb.grade?gb.grade+"年生":"学年別"));
      const list=Array.isArray(gb.ranking)?gb.ranking:[];
      add(`${posLabel} ${gradeLabel}ランキング`,list.find(r=>r.player_id===playerId));
    });
  });
  return items.sort((a,b)=>a.rank-b.rank||a.label.localeCompare(b.label,"ja"));
}
function myTopRanksHtml(rankingData,playerId){
  const items=collectMyTopRanks(rankingData,playerId);
  if(!items.length)return "";
  const overall=items.find(item=>item.label==="総合ランキング");
  const others=overall?items.filter(item=>item!==overall):items;
  const overallHtml=overall?`<div class="overall-award-hero overall-award-${escapeHtml(overall.rank)}"><div class="overall-award-crown">${Number(overall.rank)===1?"👑":"🏅"}</div><div><p>総合ランキング入賞</p><h3>${rankStars(overall.rank)} ${escapeHtml(overall.rank)}位</h3><span>全体の中で上位に入りました！</span></div></div>`:"";
  const otherHtml=others.length?`<div class="rank-award-list">${others.map(item=>`<div class="rank-award rank-award-${escapeHtml(item.rank)}"><span class="rank-award-stars">${rankStars(item.rank)}</span><b>${escapeHtml(item.label)}</b><span>${escapeHtml(item.rank)}位</span></div>`).join("")}</div>`:"";
  return `<div class="mypage-rank-awards">${overallHtml}<h3>${overall?"その他の上位ランキング":"あなたの上位ランキング"}</h3>${otherHtml}</div>`;
}







function mistakeReviewSettingKey(pid){
  return `baseballMistakeReviewEnabled:${pid||STATE.playerId||"guest"}`;
}

function enableMistakeReviewDefaultOnFirstUnlock(pid=STATE.playerId){
  if(!isFeatureUnlocked("mistake_review"))return false;
  const targetPid=pid||STATE.playerId;
  try{
    const key=mistakeReviewSettingKey(targetPid);
    const existing=localStorage.getItem(key);
    if(existing===null){
      saveMistakeReviewSetting(true,targetPid);
      localStorage.setItem("mistakeReviewEnabled","1");
      return true;
    }
    STATE.mistakeReviewEnabled=existing==="1";
    localStorage.setItem("mistakeReviewEnabled",STATE.mistakeReviewEnabled?"1":"0");
    return STATE.mistakeReviewEnabled;
  }catch(e){
    STATE.mistakeReviewEnabled=true;
    return true;
  }
}

function loadMistakeReviewSetting(pid=STATE.playerId){
  let enabled=false;
  try{
    enabled=localStorage.getItem(mistakeReviewSettingKey(pid))==="1";
  }catch(e){
    enabled=false;
  }
  STATE.mistakeReviewEnabled=enabled;
  const toggle=$("mistakeReviewToggle");
  if(toggle)toggle.checked=enabled;
  return enabled;
}
function saveMistakeReviewSetting(enabled,pid=STATE.playerId){
  STATE.mistakeReviewEnabled=!!enabled;
  try{
    localStorage.setItem(mistakeReviewSettingKey(pid),enabled?"1":"0");
  }catch(e){}
  const toggle=$("mistakeReviewToggle");
  if(toggle)toggle.checked=!!enabled;
}
function isMistakeReviewFeatureUnlocked(){
  if(STATE.adminMode)return true;
  if(typeof isFeatureUnlocked==="function"){
    return !!isFeatureUnlocked("mistake_review");
  }
  return !!(STATE.featureFlags && STATE.featureFlags.mistake_review);
}
function isMistakeReviewEnabled(){
  return !!(STATE.mistakeReviewEnabled || STATE.adminMode) && isMistakeReviewFeatureUnlocked();
}
function setMistakeReviewEnabled(enabled){
  saveMistakeReviewSetting(!!enabled,STATE.playerId);
  renderMistakeReviewSection();
}




function mistakeQuestionById(id){
  const sid=String(id||"").trim();
  if(!sid)return null;
  return (STATE.questions||[]).find(q=>String(q&&q.id||"")===sid)||null;
}
function mistakeChoicesForPosition(q,pos){
  const savedPosition=STATE.position;
  try{
    if(pos)STATE.position=pos;
    return getChoices(q)||[];
  }catch(e){
    return [];
  }finally{
    STATE.position=savedPosition;
  }
}
function mistakeCorrectChoiceForPosition(q,pos){
  const choices=mistakeChoicesForPosition(q,pos);
  return choices.find(x=>Number(x&&x.score)===3)||choices.find(x=>scoreForSelectedChoice(q,x)===3)||null;
}
function mistakeQuestionSignature(q,pos){
  if(!q)return "";
  const choices=mistakeChoicesForPosition(q,pos).map(c=>({text:String(c&&c.text||""),score:Number(c&&c.score||0)}));
  const correct=mistakeCorrectChoiceForPosition(q,pos);
  const payload=JSON.stringify({
    id:q.id||"",
    type:q.type||"",
    grade:q.grade||"",
    min_grade:q.min_grade||"",
    position:pos||"",
    outs:q.outs,
    outs_scope:q.outs_scope||"",
    stage:q.stage||"",
    theme:q.theme||"",
    ball_tag:q.ball_tag||"",
    title:displayTitle(q),
    situation:displaySituation(q),
    prompt:promptText(q),
    correctText:(correct&&correct.text)||"",
    choices
  });
  let h=2166136261;
  for(let i=0;i<payload.length;i++){
    h^=payload.charCodeAt(i);
    h=Math.imul(h,16777619);
  }
  return (h>>>0).toString(16);
}
function normalizeMistakeCompareText(v){
  return String(v||"").replace(/\s+/g," ").trim();
}
function mistakeItemLooksUpdatedWithoutHash(it,q,pos){
  if(!it||!q)return false;
  const correct=mistakeCorrectChoiceForPosition(q,pos);
  const checks=[
    [it.title,displayTitle(q)],
    [it.situation,displaySituation(q)],
    [it.prompt,promptText(q)],
    [it.correctText,(correct&&correct.text)||""]
  ];
  return checks.some(([oldV,newV])=>normalizeMistakeCompareText(oldV)!==normalizeMistakeCompareText(newV));
}
function mistakeAdviceForPosition(q,pos,correctText){
  const savedPosition=STATE.position;
  try{
    if(pos)STATE.position=pos;
    return mistakeAdvice(q,correctText);
  }catch(e){
    return mistakeAdvice(q,correctText);
  }finally{
    STATE.position=savedPosition;
  }
}
function displayMistakeReviewItem(it){
  const q=mistakeQuestionById(it&&it.questionId);
  const pos=(it&&it.position)||STATE.position;
  if(!q){
    return {
      raw:it||{},
      unavailable:true,
      updated:true,
      type:(it&&it.type)||"",
      position:pos,
      questionId:(it&&it.questionId)||"",
      title:(it&&it.title)||"",
      situation:normalizeRunnerLeadText((it&&it.situation)||""),
      outs:(it&&it.outs),
      stage:(it&&it.stage)||"",
      runnerContext:stageLabel((it&&it.stage)||"").replace("走者：","ランナー："),
      ownContext:"",
      selectedText:(it&&it.selectedText)||"",
      correctText:(it&&it.correctText)||"",
      advice:"この問題は更新または停止されました。現在の問題一覧にはないため、保存時点の内容を表示しています。",
      tags:(it&&it.tags)||[]
    };
  }
  const currentHash=mistakeQuestionSignature(q,pos);
  const savedHash=(it&&it.questionHash)||"";
  const correct=mistakeCorrectChoiceForPosition(q,pos);
  const correctText=(correct&&correct.text)||((it&&it.correctText)||"");
  const updated=savedHash? savedHash!==currentHash : mistakeItemLooksUpdatedWithoutHash(it,q,pos);
  return {
    raw:it||{},
    unavailable:false,
    updated,
    type:q.type||((it&&it.type)||""),
    position:pos,
    questionId:q.id||((it&&it.questionId)||""),
    title:displayTitle(q),
    situation:displaySituation(q),
    prompt:promptText(q),
    outs:q.outs!==undefined&&q.outs!==null&&q.outs!==""?q.outs:(it&&it.outs),
    stage:q.stage||((it&&it.stage)||""),
    runnerContext:mistakeRunnerContextLabel(q),
    ownContext:mistakeOwnRunnerLabel(q),
    selectedText:(it&&it.selectedText)||"",
    correctText,
    advice:mistakeAdviceForPosition(q,pos,correctText),
    tags:mistakeSkillTags(q)
  };
}
function mistakeReviewItemHtml(view){
  const it=view.raw||{};
  const typeLabel=view.type==="attack"?"攻撃":(view.type==="defense"?`守備：${escapeHtml((STATE.config.positions&&STATE.config.positions[view.position])||view.position||"")}`:"基本");
  const mastered=it.mastered?'<span class="mastered">克服済み</span>':"";
  const updated=view.updated?'<span class="mistake-updated">更新済み</span>':"";
  const stopped=view.unavailable?'<span class="mistake-stopped">停止/削除</span>':"";
  const updateNote=view.updated&&!view.unavailable?'<div class="mistake-update-note">この問題は内容が更新されました。現在の正しい内容で表示しています。</div>':"";
  const runnerMeta=[outsLabel(view.outs),view.runnerContext||"",view.ownContext||""].filter(Boolean).join(" / ");
  return `<div class="mistake-item ${view.updated?"is-updated":""} ${view.unavailable?"is-unavailable":""}">
    <div class="mistake-item-head"><b>${escapeHtml(view.questionId)}</b><span>${typeLabel}</span>${mastered}${updated}${stopped}<span>ミス ${escapeHtml(it.missCount||0)}回</span></div>
    <div class="mistake-title">${escapeHtml(view.title||"")}</div>
    <div class="mistake-meta">${escapeHtml(view.situation||"")}</div>
    <div class="mistake-meta">${escapeHtml(runnerMeta)}</div>
    <div class="mistake-answer"><span>前回の選択：${escapeHtml(view.selectedText||"-")}</span><span>現在の正解：${escapeHtml(view.correctText||"-")}</span></div>
    ${updateNote}
    <details class="mistake-advice"><summary>アドバイスを見る</summary><p>${escapeHtml(view.advice||"")}</p><div class="mistake-tags">${(view.tags||[]).map(t=>`<em>${escapeHtml(t)}</em>`).join("")}</div></details>
  </div>`;
}



let MISTAKE_REVIEW_PAGE=1;
let MISTAKE_REVIEW_UNAVAILABLE_PAGE=1;
let MISTAKE_REVIEW_LIST_OPEN=true;
let MISTAKE_REVIEW_UNAVAILABLE_OPEN=true;
function renderMistakeReviewSection(){
  const box=$("myPageMistakes");
  if(!box)return;
  if(!isMistakeReviewEnabled()){
    if(!isFeatureUnlocked("mistake_review")&&!STATE.adminMode){
      box.innerHTML='<div class="mistake-review disabled"><h3>間違いプレイチェック</h3><p>この機能は招待IDで解放すると利用できます。マイページ上部の「招待IDで機能を解放」から招待IDを登録してください。</p></div>';
    }else{
      box.innerHTML='<div class="mistake-review disabled"><h3>間違いプレイチェック</h3><p>現在オフです。設定画面でオンにすると、0点・1点の問題を記録します。</p></div>';
    }
    return;
  }
  if(!STATE.playerId){
    box.innerHTML="";
    return;
  }
  const data=loadMistakeReview(STATE.playerId);
  const items=Object.values(data.items||{}).filter(x=>Number(x.missCount||0)>0).sort((a,b)=>{
    const m=Number(b.missCount||0)-Number(a.missCount||0);
    if(m)return m;
    return String(b.lastMissedAt||"").localeCompare(String(a.lastMissedAt||""));
  });
  if(!items.length){
    box.innerHTML='<div class="mistake-review"><h3>間違いプレイチェック</h3><div class="mypage-empty">まだ記録された間違いはありません。0点・1点の問題がここに表示されます。</div></div>';
    return;
  }
  const views=items.map(displayMistakeReviewItem);
  const activeViews=views.filter(v=>!v.unavailable);
  const unavailableViews=views.filter(v=>v.unavailable);
  const tags=tagSummaryFromMistakes(activeViews.map(v=>({...(v.raw||{}),tags:v.tags||[]})));
  const tagHtml=tags.length?`<div class="weak-tags">${tags.map(([t,n])=>`<span>${escapeHtml(t)} <b>${escapeHtml(n)}</b></span>`).join("")}</div>`:"";

  // ページネーション（10個以上で10個/ページ）
  const itemsPerPage=10;
  const totalPages=Math.ceil(activeViews.length/itemsPerPage);
  const showPagination=activeViews.length>=10;
  if(showPagination)MISTAKE_REVIEW_PAGE=Math.max(1,Math.min(MISTAKE_REVIEW_PAGE,totalPages));

  const startIdx=(MISTAKE_REVIEW_PAGE-1)*itemsPerPage;
  const endIdx=startIdx+itemsPerPage;
  const pageViews=showPagination?activeViews.slice(startIdx,endIdx):activeViews.slice(0,30);
  const listHtml=pageViews.map(mistakeReviewItemHtml).join("");

  // ページネーションコントロール
  const paginationHtml=showPagination?`<div class="mistake-review-pagination">
    <button class="secondary" data-page-action="prev" ${MISTAKE_REVIEW_PAGE<=1?'disabled':''}>&lt; 前へ</button>
    <span class="page-info">ページ ${MISTAKE_REVIEW_PAGE} / ${totalPages}</span>
    <button class="secondary" data-page-action="next" ${MISTAKE_REVIEW_PAGE>=totalPages?'disabled':''}>次へ &gt;</button>
  </div>`:"";

  // 「更新または停止された問題」ページネーション（5個/ページ）
  const unavailableItemsPerPage=5;
  const unavailableTotalPages=Math.ceil(unavailableViews.length/unavailableItemsPerPage);
  const showUnavailablePagination=unavailableViews.length>=5;
  if(showUnavailablePagination)MISTAKE_REVIEW_UNAVAILABLE_PAGE=Math.max(1,Math.min(MISTAKE_REVIEW_UNAVAILABLE_PAGE,unavailableTotalPages));

  const unavailableStartIdx=(MISTAKE_REVIEW_UNAVAILABLE_PAGE-1)*unavailableItemsPerPage;
  const unavailableEndIdx=unavailableStartIdx+unavailableItemsPerPage;
  const unavailablePageViews=showUnavailablePagination?unavailableViews.slice(unavailableStartIdx,unavailableEndIdx):unavailableViews.slice(0,20);

  const unavailablePaginationHtml=showUnavailablePagination?`<div class="mistake-review-pagination">
    <button class="secondary" data-unavailable-page-action="prev" ${MISTAKE_REVIEW_UNAVAILABLE_PAGE<=1?'disabled':''}>&lt; 前へ</button>
    <span class="page-info">ページ ${MISTAKE_REVIEW_UNAVAILABLE_PAGE} / ${unavailableTotalPages}</span>
    <button class="secondary" data-unavailable-page-action="next" ${MISTAKE_REVIEW_UNAVAILABLE_PAGE>=unavailableTotalPages?'disabled':''}>次へ &gt;</button>
  </div>`:"";

  const unavailableHtml=unavailableViews.length?`<h4 class="mistake-accordion-title" data-accordion="unavailable"><span class="accordion-icon">${MISTAKE_REVIEW_UNAVAILABLE_OPEN?'▼':'▶'}</span> 更新または停止された問題</h4><div class="mistake-accordion-content" data-accordion-content="unavailable" style="display:${MISTAKE_REVIEW_UNAVAILABLE_OPEN?'block':'none'}">${unavailablePaginationHtml}<p class="mistake-note">現在の問題一覧にない記録です。保存時点の内容を参考表示しています。</p>${unavailablePageViews.map(mistakeReviewItemHtml).join("")}</div>`:"";
  box.innerHTML=`<div class="mistake-review"><h3>間違いプレイチェック</h3><p class="mistake-note">0点・1点だった問題をこの端末に記録しています。問題が更新された場合は、最新の問題文・正解・アドバイスで表示します。</p><h4>苦手傾向</h4>${tagHtml}<h4 class="mistake-accordion-title" data-accordion="list"><span class="accordion-icon">${MISTAKE_REVIEW_LIST_OPEN?'▼':'▶'}</span> 間違えた問題一覧</h4><div class="mistake-accordion-content" data-accordion-content="list" style="display:${MISTAKE_REVIEW_LIST_OPEN?'block':'none'}">${paginationHtml}${listHtml||'<div class="mypage-empty">現在表示できる間違い記録はありません。</div>'}</div>${unavailableHtml}</div>`;

  // 「間違えた問題一覧」ページネーションボタンのイベントリスナー
  if(showPagination){
    box.querySelectorAll('[data-page-action]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        if(btn.getAttribute('data-page-action')==='prev'&&MISTAKE_REVIEW_PAGE>1){
          MISTAKE_REVIEW_PAGE--;
        }else if(btn.getAttribute('data-page-action')==='next'&&MISTAKE_REVIEW_PAGE<totalPages){
          MISTAKE_REVIEW_PAGE++;
        }
        renderMistakeReviewSection();
      });
    });
  }

  // 「更新または停止された問題」ページネーションボタンのイベントリスナー
  if(showUnavailablePagination){
    box.querySelectorAll('[data-unavailable-page-action]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        if(btn.getAttribute('data-unavailable-page-action')==='prev'&&MISTAKE_REVIEW_UNAVAILABLE_PAGE>1){
          MISTAKE_REVIEW_UNAVAILABLE_PAGE--;
        }else if(btn.getAttribute('data-unavailable-page-action')==='next'&&MISTAKE_REVIEW_UNAVAILABLE_PAGE<unavailableTotalPages){
          MISTAKE_REVIEW_UNAVAILABLE_PAGE++;
        }
        renderMistakeReviewSection();
      });
    });
  }

  // アコーディオン制御
  box.querySelectorAll('[data-accordion]').forEach(title=>{
    title.style.cursor='pointer';
    title.addEventListener('click',()=>{
      const accordion=title.getAttribute('data-accordion');
      if(accordion==='list'){
        MISTAKE_REVIEW_LIST_OPEN=!MISTAKE_REVIEW_LIST_OPEN;
      }else if(accordion==='unavailable'){
        MISTAKE_REVIEW_UNAVAILABLE_OPEN=!MISTAKE_REVIEW_UNAVAILABLE_OPEN;
      }
      renderMistakeReviewSection();
    });
  });
}
function recordMistakeReview(q,choice,score){
  if(isAdminQuestionTestMode())return;
  if(!isMistakeReviewEnabled() || !STATE.playerId || !q)return;
  const correct=correctChoiceForQuestion(q);
  const correctText=(correct&&correct.text)||"";
  const selectedText=(choice&&choice.text)||"";
  const data=loadMistakeReview(STATE.playerId);
  const key=q.id||`${q.type}_${q.theme}_${q.stage}_${q.outs}`;
  const now=new Date().toISOString();
  const wasMiss=Number(score)<3;
  const prev=data.items[key]||{};
  const item={
    questionId:q.id||key,
    type:q.type||"",
    grade:STATE.grade,
    position:STATE.position,
    inning:q.inning||"",
    outs:q.outs,
    stage:q.stage||"",
    title:displayTitle(q),
    situation:displaySituation(q),
    prompt:promptText(q),
    selectedText:wasMiss?selectedText:(prev.selectedText||selectedText),
    correctText:correctText||(prev.correctText||""),
    lastScore:Number(score),
    missCount:Number(prev.missCount||0)+(wasMiss?1:0),
    tryCount:Number(prev.tryCount||0)+1,
    mastered:wasMiss?!!prev.mastered:true,
    firstMissedAt:prev.firstMissedAt||(wasMiss?now:prev.firstMissedAt||""),
    lastMissedAt:wasMiss?now:(prev.lastMissedAt||""),
    lastAnsweredAt:now,
    tags:mistakeSkillTags(q),
    advice:mistakeAdvice(q,correctText),
    questionHash:mistakeQuestionSignature(q,STATE.position),
    questionHashVersion:1
  };
  if(item.missCount>0)data.items[key]=item;
  saveMistakeReview(data,STATE.playerId);
  renderMistakeReviewSection();
  setTimeout(()=>syncMistakesToServer(true),1200);
}




async function fetchJsonWithTimeout(url,options={},timeoutMs=7000){
  const controller=new AbortController();
  const timer=setTimeout(()=>controller.abort(),timeoutMs);
  try{
    const res=await fetch(url,Object.assign({},options,{signal:controller.signal}));
    const text=await res.text();
    let data=null;
    try{data=text?JSON.parse(text):{};}catch(e){data={ok:false,error:"invalid json",raw:text.slice(0,300)};}
    return {res,data};
  }finally{
    clearTimeout(timer);
  }
}
function localScoreHistoryKey(pid){
  return `baseballLocalScores:${pid||STATE.playerId||"guest"}`;
}






function renderQuizMasterMyPage(data){
  const box=$("myPageQuizMaster");
  if(!box)return;
  if(!data||!data.ok){
    box.innerHTML='<div class="mypage-empty">野球博士チャレンジの成績を読み込めませんでした。</div>';
    return;
  }
  const best=data.my_best||null;
  const total=data.my_total||null;
  const recent=Array.isArray(data.my_recent)?data.my_recent:[];
  if(!best&&!recent.length){
    box.innerHTML='<div class="quiz-master-mypage-card"><h3>野球博士チャレンジ</h3><div class="mypage-empty">まだ保存された点数がありません。ログインしてプレイするとここに表示されます。</div></div>';
    return;
  }
  const latest=recent[0]||null;
  const totalScore=Number(total&&total.total_score!==undefined?total.total_score:recent.reduce((sum,r)=>sum+Number(r.score||0),0));
  const titleInfo=(total&&total.title_info)||quizMasterTitleForScore(totalScore,data.titles);
  const recentRows=recent.length?`<div class="records-table quiz-master-mypage-table"><table><thead><tr><th>日時</th><th>得点</th><th>到達</th><th>結果</th></tr></thead><tbody>${recent.slice(0,5).map(r=>`<tr><td>${escapeHtml(r.played_at||"")}</td><td><b>${escapeHtml(r.score||0)} pt</b></td><td>第${escapeHtml(r.reached_level||0)}問</td><td>${escapeHtml(quizMasterResultReasonText(r.result_reason))}</td></tr>`).join("")}</tbody></table></div>`:"";
  box.innerHTML=`<div class="quiz-master-mypage-card"><h3>野球博士チャレンジ</h3><div class="quiz-master-current-title">${quizMasterLevelIconHtml(titleInfo.level,'qm-icon-mypage')}<em>${escapeHtml(totalScore)} pt</em></div><div class="summary-grid quiz-master-mypage-summary"><div><b>${escapeHtml(best?best.score:0)} pt</b><span>最高点</span></div><div><b>${escapeHtml(total&&total.rank?total.rank:"-")}位</b><span>総合順位</span></div><div><b>${escapeHtml(latest?latest.score:0)} pt</b><span>最新得点</span></div><div><b>${escapeHtml(totalScore)} pt</b><span>総合点</span></div></div>${recentRows}</div>`;
}
function renderMyPage(data,rankingData,quizMasterData){
  const summary=(data&&data.summary)||{};
  const records=Array.isArray(data&&data.records)?data.records:[];
  const playerId=(data&&data.player_id)||STATE.playerId||"";
  const profile=$("myPageProfile");
  const summaryEl=$("myPageSummary");
  const recordsEl=$("myPageRecords");
  if(profile)profile.innerHTML=`<div class="profile-id">プレイヤーID：${escapeHtml(playerId)}</div><div>保存済み成績：${escapeHtml(summary.play_count||0)}回</div>`;
  if(summaryEl){
    summaryEl.innerHTML=`<div class="summary-grid"><div><b>${escapeHtml(summary.correct_count||0)}</b><span>クリア問題数</span></div><div><b>${escapeHtml(summary.best_score||0)}</b><span>最高点</span></div><div><b>${escapeHtml(fmtScore(summary.average_score||0))}</b><span>平均点</span></div><div><b class="latest-play-time">${summary.latest_played_at?latestPlayHtml(summary.latest_played_at):"-"}</b><span>最新プレイ</span></div></div>${myTopRanksHtml(rankingData,playerId)}`;
  }
  if(recordsEl){
    if(!records.length){
      recordsEl.innerHTML='<div class="mypage-empty">まだ保存された成績がありません。ゲームをプレイするとここに結果が表示されます。</div>';
    }else{
      recordsEl.innerHTML=`<h3>過去の結果</h3><div class="records-table"><table><thead><tr><th>日時</th><th>学年</th><th>守備</th><th>合計</th><th>クリア問題数</th><th>攻撃</th><th>守備</th></tr></thead><tbody>${records.map(r=>`<tr><td>${escapeHtml(r.played_at||"")}</td><td>${escapeHtml(r.grade||"")}年</td><td>${escapeHtml((STATE.config.positions&&STATE.config.positions[r.position])||r.position||"")}</td><td><b>${escapeHtml(r.total_score||0)}/${escapeHtml(r.max_score||54)}</b></td><td>${escapeHtml(r.correct_count||0)}問</td><td>${escapeHtml(r.attack_score||0)}</td><td>${escapeHtml(r.defense_score||0)}</td></tr>`).join("")}</tbody></table></div>`;
    }
  }
  renderQuizMasterMyPage(quizMasterData);

  try{renderFeatureUnlockSection();}catch(e){console.warn("renderFeatureUnlockSection failed",e);}
  try{renderMistakeReviewSection();}catch(e){console.warn("renderMistakeReviewSection failed",e);}
}


function mistakeStorageKey(pid){
  return `baseballMistakes:${pid||STATE.playerId||"guest"}`;
}






function loadMistakeReview(pid){
  try{
    const raw=localStorage.getItem(mistakeStorageKey(pid));
    const data=raw?JSON.parse(raw):{items:{}};
    if(Array.isArray(data)){const items={};data.forEach((x,i)=>{const key=x.questionId||x.question_id||x.id||`mistake_${i}`;items[key]=x;});return {items};}
    if(!data || typeof data!=="object")return {items:{}};
    if(Array.isArray(data.records)){const items={};data.records.forEach((x,i)=>{const key=x.questionId||x.question_id||x.id||`mistake_${i}`;items[key]=x;});data.items=items;}
    if(!data.items || typeof data.items!=="object")data.items={};
    return data;
  }catch(e){
    console.warn("mistake review load failed",e);
    return {items:{}};
  }
}
function saveMistakeReview(data,pid){
  try{
    localStorage.setItem(mistakeStorageKey(pid),JSON.stringify(data||{items:{}}));
  }catch(e){
    console.warn("mistake review save failed",e);
  }
}
function correctChoiceForQuestion(q){
  const choices=getChoices(q)||[];
  return choices.find(x=>Number(x.score)===3)||choices.find(x=>scoreForSelectedChoice(q,x)===3)||null;
}
function mistakeSkillTags(q){
  const story=normalizeSimilarText(`${q.id||""} ${q.type||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${(q.visual&&q.visual.ball_path)||""} ${(q.visual&&q.visual.target_position)||""}`);
  const tags=[];
  if(q.type==="attack")tags.push("攻撃判断");
  else if(q.type==="defense")tags.push("守備判断");
  else tags.push("基本動作");
  if(/フォース|force/.test(story))tags.push("フォースプレイ");
  if(/声|声かけ|自分で/.test(story))tags.push("声かけ");
  if(/カバー|cover/.test(story))tags.push("カバー");
  if(/ゴロ|ground/.test(story))tags.push("ゴロ判断");
  if(/フライ|fly/.test(story))tags.push("フライ判断");
  if(/ライナー|line/.test(story))tags.push("ライナー判断");
  if(/バント|スクイズ|bunt|squeeze/.test(story))tags.push("バント・スクイズ");
  if(/牽制|pickoff/.test(story))tags.push("牽制");
  if(/盗塁|steal/.test(story))tags.push("盗塁");
  if(/本塁|ホーム/.test(story))tags.push("本塁判断");
  if(/一塁|ファースト|1B/.test(story))tags.push("一塁判断");
  if(/二塁|セカンド|2B/.test(story))tags.push("二塁判断");
  if(/三塁|サード|3B/.test(story))tags.push("三塁判断");
  return [...new Set(tags)].slice(0,6);
}
function mistakeAdvice(q,correctText){
  const tags=mistakeSkillTags(q);
  const title=displayTitle(q);
  let base=`この問題は「${title}」の判断です。まずアウトカウント、走者、打球方向を順番に確認しましょう。`;
  if(tags.includes("フォースプレイ"))base+=" フォースアウトが取れる場面では、どのベースで一番確実にアウトにできるかを先に考えます。";
  if(tags.includes("声かけ"))base+=" 自分が直接プレイしない場面でも、近い味方に何をしてほしいか声を出すことが大切です。";
  if(tags.includes("カバー"))base+=" ボールを捕る人だけでなく、送球がそれた時やベースが空く時のカバーも役割です。";
  if(tags.includes("フライ判断")||tags.includes("ライナー判断"))base+=" フライ・ライナーでは、走者は飛び出しすぎず、捕られた時に戻れるかを考えます。";
  if(correctText)base+=` 次は「${correctText}」を基準に覚えましょう。`;
  return base;
}

function tagSummaryFromMistakes(items){
  const counts={};
  items.forEach(it=>(it.tags||[]).forEach(t=>{counts[t]=(counts[t]||0)+Number(it.missCount||1)}));
  return Object.entries(counts).sort((a,b)=>b[1]-a[1]).slice(0,6);
}



function formatNoticeDate(value){
  const s=String(value||"").trim();
  if(!s)return "";
  const parts=s.split(" ");
  if(parts.length>=2){
    return `${parts[0].replace(/^\d{2}(\d{2})-/,"$1-")} ${parts[1].slice(0,5)}`;
  }
  return s;
}
async function openNotices(){
  closeTopMenu();
  show("screen-notices");
  renderNoticesLoading();
  try{
    const res=await fetch("api/get_public_notices.php",{cache:"no-store"});
    const data=await res.json().catch(()=>({ok:false}));
    if(!res.ok||!data.ok)throw new Error((data&&data.error)||"load failed");
    renderNotices(data.notices||[]);
    renderPublicVersionInfo();
  }catch(e){
    console.warn("notices load failed",e);
    renderNoticesError();
  }
}
function renderNoticesLoading(){
  const summary=$("noticeListSummary");
  const records=$("noticeListRecords");
  if(summary)summary.innerHTML="<div>お知らせを読み込み中...</div>";
  if(records)records.innerHTML="";
}
function renderNoticesError(){
  const summary=$("noticeListSummary");
  const records=$("noticeListRecords");
  if(summary)summary.innerHTML='<div class="mypage-empty">お知らせを読み込めませんでした。通信環境を確認して再度お試しください。</div>';
  if(records)records.innerHTML="";
}
function renderNotices(notices){
  const summary=$("noticeListSummary");
  const records=$("noticeListRecords");
  if(!summary||!records)return;
  if(!Array.isArray(notices)||!notices.length){
    summary.innerHTML='<div class="mypage-empty">現在表示できるお知らせはありません。</div>';
    records.innerHTML="";
    return;
  }
  summary.innerHTML=`<div>表示件数：${escapeHtml(notices.length)}件</div>`;
  records.innerHTML=`<div class="notice-list">${notices.map((n,i)=>{
    const title=escapeHtml(n.title||"お知らせ");
    const date=escapeHtml(formatNoticeDate(n.sent_at));
    const body=escapeHtml(n.body||"").replace(/\n/g,"<br>");
    return `<details class="notice-item"${i===0?" open":""}>
      <summary>
        <span class="notice-item-title">${title}</span>
        <span class="notice-item-date">${date}</span>
      </summary>
      <div class="notice-item-body">${body||"本文はありません。"}</div>
    </details>`;
  }).join("")}</div>`;
}


function renderMyPageLoading(){
  const summary=$("myPageSummary");
  const quiz=$("myPageQuizMaster");
  const records=$("myPageRecords");
  if(summary)summary.innerHTML='<div class="mypage-empty">成績を読み込み中...</div>';
  if(quiz)quiz.innerHTML='<div class="mypage-empty">野球博士チャレンジの成績を読み込み中...</div>';
  if(records)records.innerHTML='<div class="mypage-empty">成績を読み込み中...</div>';
}
function renderMyPageError(message){
  const summary=$("myPageSummary");
  const quiz=$("myPageQuizMaster");
  const records=$("myPageRecords");
  if(summary)summary.innerHTML='<div class="mypage-empty">成績を読み込めませんでした。</div>';
  if(quiz)quiz.innerHTML="";
  if(records)records.innerHTML=`<div class="mypage-empty">${escapeHtml(message||"通信環境を確認して再度お試しください。")}</div>`;
}
async function openMyPage(){
  if(!hasLoggedInPlayerForIssueActions()){
    if(!(await loginWithCurrentId()))return;
  }

  show("screen-mypage");
  renderMyPageLoading();
  loadMistakeReviewSetting(STATE.playerId);

  let scoreData=null;
  let rankingData=null;
  let quizMasterData=null;

  try{
    const scoreResult=await fetchJsonWithTimeout("api/get_scores.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({player_id:STATE.playerId,client_token:getClientToken(),t:Date.now()})
    },7000);
    scoreData=scoreResult.data;
    if(!scoreResult.res.ok||!scoreData||!scoreData.ok){
      throw new Error((scoreData&&scoreData.error)||`score api failed ${scoreResult.res.status}`);
    }
  }catch(scoreErr){
    console.warn("mypage score load failed",scoreErr);
    renderMyPageError("サーバーの成績を読み込めませんでした。通信環境を確認して再度お試しください。");
    return;
  }

  try{
    const rankingResult=await fetchJsonWithTimeout("api/get_ranking.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({player_id:STATE.playerId,client_token:getClientToken(),t:Date.now()})
    },7000);
    const tmp=rankingResult.data;
    if(rankingResult.res.ok&&tmp&&tmp.ok)rankingData=tmp;
  }catch(rankingErr){
    console.warn("mypage ranking load failed",rankingErr);
    rankingData=null;
  }
  try{
    const quizResult=await fetchJsonWithTimeout("api/get_quiz_master_ranking.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({player_id:STATE.playerId,client_token:getClientToken(),t:Date.now()})
    },7000);
    const tmp=quizResult.data;
    if(quizResult.res.ok&&tmp&&tmp.ok)quizMasterData=tmp;
  }catch(quizErr){
    console.warn("mypage quiz master load failed",quizErr);
    quizMasterData=null;
  }

  try{
    await refreshFeatureFlags(STATE.playerId);
  }catch(featureErr){
    console.warn("mypage feature refresh failed",featureErr);
  }
  loadMistakeReviewSetting(STATE.playerId);

  try{
    renderMyPage(scoreData,rankingData,quizMasterData);
  }catch(renderErr){
    console.warn("renderMyPage failed",renderErr);
    renderMyPageError("成績データは取得できましたが、表示処理で問題が発生しました。");
    return;
  }

  try{renderFeatureUnlockSection();}catch(e){console.warn("renderFeatureUnlockSection failed",e);}
  try{renderMistakeReviewSection();}catch(e){console.warn("renderMistakeReviewSection failed",e);}
}


async function openRanking(){if(!STATE.loggedIn||!STATE.playerId){if(!(await loginWithCurrentId()))return}show("screen-ranking");renderRankingLoading();try{const res=await fetch("api/get_ranking.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({player_id:STATE.playerId})});const data=await res.json();if(!res.ok||!data.ok)throw new Error((data&&data.error)||"load failed");renderRanking(data)}catch(e){console.warn("ranking load failed",e);renderRankingError()}}
function renderRankingLoading(){$("rankingSummary").innerHTML=`<div class="profile-id">プレイヤーID：${escapeHtml(STATE.playerId)}</div><div>ランキングを読み込み中...</div>`;$("rankingRecords").innerHTML=""}
function renderRankingError(){$("rankingSummary").innerHTML='<div class="mypage-empty">ランキングを読み込めませんでした。PHPが利用できるサーバーに設置されているか確認してください。</div>';$("rankingRecords").innerHTML=""}
function renderRanking(data){
  const rows=Array.isArray(data.overall_ranking)?data.overall_ranking:(Array.isArray(data.ranking)?data.ranking:[]);
  const posRankings=data.position_rankings||{};
  const posLabels=data.position_labels||{};
  const posOrder=Array.isArray(data.position_order)&&data.position_order.length?data.position_order:["P","C","1B","2B","SS","3B","LF","CF","RF"];
  const removed=Number(data.deleted_stale_users||0);
  $("rankingSummary").innerHTML=`<div class="profile-id">プレイヤーID：${escapeHtml(STATE.playerId)}</div><div>表示対象：直近3カ月以内にプレイしたユーザー</div>${removed?`<div class="ranking-cleanup">3カ月以上更新のないユーザー ${escapeHtml(removed)}件を削除しました。</div>`:""}`;

  const overallHtml=rows.length?`<h3>クリア問題数ランキング 1位〜50位</h3><details class="ranking-note ranking-note-details"><summary>ランキング解説</summary><p>ランキングは、プレイ回数ではなくクリア問題数を基準に表示します。同じ問題を複数回正解しても1問として数えます。クリア問題数が同じ場合は、到達学年、平均得点、平均回答時間、最高点、最新プレイの順で判定します。</p></details><div class="records-table ranking-table"><table><thead><tr><th>順位</th><th>プレイヤーID</th><th>クリア問題数</th><th>平均得点</th><th>ポジション数</th><th>学年</th><th>最高点</th><th>平均回答時間</th><th>最新プレイ</th></tr></thead><tbody>${rows.map(r=>`<tr class="${r.player_id===STATE.playerId?"is-current-user":""} ${Number(r.rank)<=3?"overall-top overall-top-"+Number(r.rank):""}"><td><b>${escapeHtml(r.rank)}位</b>${rankStars(r.rank)}</td><td>${escapeHtml(r.player_id)}</td><td><b>${escapeHtml(r.correct_count||0)}問</b></td><td>${escapeHtml(fmtScore(r.average_score))}/54</td><td>${escapeHtml(r.position_count||0)}ポジション</td><td>${escapeHtml(r.max_grade_label||gradeLabel(r.max_grade)||"-")}</td><td>${escapeHtml(r.best_score)}/54</td><td>${escapeHtml(fmtTimeSeconds(r.average_answer_seconds))}</td><td>${escapeHtml(fmtDate(r.latest_played_at))}</td></tr>`).join("")}</tbody></table></div>`:'<div class="mypage-empty">まだランキング対象の成績がありません。</div>';

  const gradeTable=(gradeBlock,label)=>{
    const list=Array.isArray(gradeBlock&&gradeBlock.ranking)?gradeBlock.ranking:[];
    if(!list.length) return `<div class="grade-ranking-block"><h4>${escapeHtml(label)}</h4><div class="mypage-empty">まだ成績がありません。</div></div>`;
    return `<div class="grade-ranking-block"><h4>${escapeHtml(label)}</h4><div class="records-table ranking-table grade-ranking-table"><table><thead><tr><th>順位</th><th>プレイヤーID</th><th>回答学年</th><th>クリア問題数</th><th>平均得点</th><th>平均回答時間</th><th>最高点</th><th>最新プレイ</th></tr></thead><tbody>${list.map(r=>`<tr class="${r.player_id===STATE.playerId?"is-current-user":""}"><td><b>${escapeHtml(r.rank)}位</b>${rankStars(r.rank)}</td><td>${escapeHtml(r.player_id)}</td><td>${escapeHtml(r.max_grade_label||gradeLabel(r.max_grade)||"-")}</td><td><b>${escapeHtml(r.correct_count||0)}問</b></td><td>${escapeHtml(fmtScore(r.average_score))}/54</td><td>${escapeHtml(fmtTimeSeconds(r.average_answer_seconds))}</td><td>${escapeHtml(r.best_score)}/54</td><td>${escapeHtml(fmtDate(r.latest_played_at))}</td></tr>`).join("")}</tbody></table></div></div>`;
  };

  const positionHtml=posOrder.map(pos=>{
    const block=posRankings[pos]||{};
    const label=block.position_label||posLabels[pos]||pos;
    const list=Array.isArray(block.ranking)?block.ranking:[];
    const highRank=block.high_grade_average_rank?`<span class="ranking-cleanup">高学年平均順位：${escapeHtml(block.high_grade_average_rank)}位</span>`:`<span class="ranking-cleanup">高学年データなし</span>`;
    const mainTable=list.length?`<div class="records-table ranking-table"><table><thead><tr><th>順位</th><th>プレイヤーID</th><th>回答学年</th><th>クリア問題数</th><th>平均得点</th><th>平均回答時間</th><th>最高点</th><th>最新プレイ</th></tr></thead><tbody>${list.map(r=>`<tr class="${r.player_id===STATE.playerId?"is-current-user":""}"><td><b>${escapeHtml(r.rank)}位</b>${rankStars(r.rank)}</td><td>${escapeHtml(r.player_id)}</td><td>${escapeHtml(r.max_grade_label||gradeLabel(r.max_grade)||"-")}</td><td><b>${escapeHtml(r.correct_count||0)}問</b></td><td>${escapeHtml(fmtScore(r.average_score))}/54</td><td>${escapeHtml(fmtTimeSeconds(r.average_answer_seconds))}</td><td>${escapeHtml(r.best_score)}/54</td><td>${escapeHtml(fmtDate(r.latest_played_at))}</td></tr>`).join("")}</tbody></table></div>`:`<div class="mypage-empty">まだ成績がありません。</div>`;
    const gradeRankings=block.grade_rankings||{};
    const gradesHtml=[3,4,5,6].map(g=>gradeTable(gradeRankings[String(g)],`${g}年生ランキング`)).join("");
    return `<div class="position-ranking-block"><h3>${escapeHtml(label)}ランキング 1位〜50位 ${highRank}</h3>${mainTable}<details class="grade-rankings"><summary>学年別ランキングを見る</summary>${gradesHtml}</details></div>`;
  }).join("");

  $("rankingRecords").innerHTML=`${overallHtml}<h3 class="position-ranking-title">守備位置別ランキング</h3><details class="ranking-note ranking-note-details"><summary>ランキング解説</summary><p>守備位置別ランキングも、クリア問題数を基準に表示します。同じ問題を複数回正解しても1問として数えます。クリア問題数が同じ場合は、平均得点、平均回答時間、最高点、最新プレイの順で判定します。プレイ回数は順位判定に使いません。</p></details>${positionHtml}`;
}

async function startGame(){
  if(isLikelyMobileOrTablet()&&!isPwaStandalone()){
    updateRegistrationAvailability();
    alert(isLineInAppBrowser()?"LINE内ブラウザではゲームを開始できません。Safariでこのページを開き、ホーム画面に追加してから起動してください。":"スマホ・iPadでは、ホーム画面に追加したアプリから開いた時だけゲームを開始できます。");
    return;
  }
  clearQuestionTimer();if(!STATE.loggedIn||STATE.playerId!==currentInputPlayerId()){if(!(await loginWithCurrentId()))return}if(!isSelectedGradeUnlocked())return;const pid=STATE.playerId;STATE.grade=Number($("grade").value);STATE.position=STATE.grade<=2?"BASIC":$("position").value;try{
    const needsQuestionLoad=!(Array.isArray(STATE.questions)&&STATE.questions.length);
    if(needsQuestionLoad)showGameDataLoading("初回のみ問題データを読み込んでいます。");
    await ensureQuestionsLoaded(true);
    if(needsQuestionLoad)hideGameDataLoading();
    STATE.sequence=makeSequence();
  }catch(e){
    hideGameDataLoading();
    console.error(e);
    alert("問題データの読み込みに問題があります。通信環境を確認して再度お試しください。");
    return;
  }trackAccessEvent("game_start",`grade=${STATE.grade};position=${STATE.position}`);STATE.current=0;STATE.score=0;STATE.attackScore=0;STATE.defenseScore=0;STATE.logs=[];show("screen-game");const title=sideStartTitle(STATE.sequence[0]);if(title)showTransitionTitle(title, renderQuestion);else renderQuestion()}

function isPitcherFirstGrounderCover(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/暴投|ワイルドピッチ|捕逸|パスボール|外野|ヒット|長打|バント|スクイズ|ピッチャー|投手|キャッチャー|捕手/.test(story))return false;
  return /ファーストゴロ|一塁線|first_grounder|first_base/.test(story);
}
function pitcherFirstGrounderCoverChoices(q){
  return [
    {text:"一塁カバーに入る",score:3,explain:"ファーストゴロでは、ファーストが打球を追って一塁ベースを空けることがある。ピッチャーは一塁カバーに入り、送球やベースタッチに備えるのが基本。"},
    {text:"本塁方向だけを見て待つ",score:0,explain:"走者は気になるが、ファーストゴロでは一塁カバーが遅れるとアウトを取れない。"},
    {text:"ボールの行方を確認してからゆっくり動く",score:1,explain:"確認は必要だが、ピッチャーはすぐ一塁カバーの準備に入る必要がある。"}
  ];
}

function isPitcherFirstBaseBuntCover(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/暴投|ワイルドピッチ|捕逸|パスボール|外野|ヒット|長打/.test(story))return false;
  return /一塁側.*バント|ファーストが前進|一塁ベースが空く|cover_bunt_rotation/.test(story);
}
function pitcherFirstBaseBuntCoverChoices(q){
  return [
    {text:"一塁カバーに入る",score:3,explain:"一塁側バントではファーストが前に出るため、ピッチャーは一塁カバーに入る。"},
    {text:"ボールの行方を見る",score:1,explain:"確認は必要だが、一塁カバーが遅れるとアウトを取りにくい。"},
    {text:"特に何もしない",score:0,explain:"一塁が空く場面では、カバーに入る必要がある。"}
  ];
}

function isTwoOutOrdinaryInfieldGrounder(q){
  if(!q || q.type!=="defense" || Number(q.outs)!==2)return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/ピッチャー|キャッチャー|捕手|バント|スクイズ|暴投|ワイルドピッチ|捕逸|パスボール/.test(story))return false;
  return /ファーストゴロ|セカンドゴロ|ショートゴロ|サードゴロ|first_grounder|first_base|second_grounder|short_grounder|third_grounder/.test(story);
}
function twoOutPitcherGrounderChoices(q){
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${(q.visual&&q.visual.ball_path)||""}`);
  const isFirst=/ファーストゴロ|first_grounder|first_base/.test(story);
  if(isFirst){
    return [
      {text:"一塁カバーに入り、ファーストからの送球やベースタッチに備える",score:3,explain:"2アウトでは一塁で打者走者をアウトにすれば攻撃終了。ファーストが打球処理で一塁を離れる可能性があるため、ピッチャーは一塁カバーに入るのが大切。"},
      {text:"本塁送球の後ろをバックアップする",score:1,explain:"三塁走者は気になるが、2アウトの内野ゴロはまず一塁アウトで攻撃終了を狙う場面。"},
      {text:"ボールの行方を見てからゆっくり動く",score:0,explain:"一塁カバーが遅れると、打者走者をアウトにするチャンスを逃す。"}
    ];
  }
  return [
    {text:"一塁へ送球するように声をかけ、送球後のカバーに備える",score:3,explain:"2アウトでは、一塁で打者走者をアウトにすれば攻撃終了。セカンド・ショート・サードのゴロでは、本塁ではなく一塁送球を味方に伝える。"},
    {text:"本塁送球の後ろをバックアップする",score:1,explain:"2アウトなので本塁で三塁走者をアウトにするより、一塁で打者走者をアウトにする方が基本。"},
    {text:"ボールの行方を見てから動く",score:0,explain:"2アウトの内野ゴロでは、すぐに一塁アウトを取る声かけとカバー意識が必要。"}
  ];
}
function isTwoOutPitcherFrontGrounderOrBunt(q){
  if(!q || q.type!=="defense" || Number(q.outs)!==2)return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/暴投|ワイルドピッチ|捕逸|パスボール|外野|ヒット|長打/.test(story))return false;
  return /ピッチャー|投手|バント|スクイズ|pitcher_grounder|unknown_to_pitcher|\bbunt\b|squeeze_bunt/.test(story) && /ゴロ|転がった|バント|スクイズ|grounder|bunt/.test(story);
}
function twoOutPitcherFrontGrounderOrBuntChoices(q){
  return [
    {text:"一塁で打者走者をアウトにするため、体勢を整えて一塁へ低く正確に送球する",score:3,explain:"2アウトでは、一塁で打者走者をアウトにすれば攻撃終了。本塁送球が難しい場面でも、まず一塁アウトを狙うのが基本。"},
    {text:"本塁送球だけを考える",score:0,explain:"2アウトでは本塁で三塁走者をアウトにしなくても、一塁で打者走者をアウトにすれば攻撃終了。"},
    {text:"投げずに近い味方へ返す",score:1,explain:"無理な送球を避ける考えは必要だが、2アウトで一塁送球が間に合うなら打者走者を一塁でアウトにする判断が優先。"}
  ];
}
function isTwoOutOutfieldHitForPitcher(q){
  if(!q || q.type!=="defense" || Number(q.outs)!==2)return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/ゴロ|grounder|バント|スクイズ|暴投|ワイルドピッチ|捕逸|パスボール|牽制/.test(story))return false;
  return /センター前|レフト前|ライト前|外野|ヒット|長打|left_single|center_single|right_single|left_center_gap|right_center_gap|left_line|right_line/.test(story);
}
function twoOutPitcherOutfieldHitChoices(q){
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.stage||""} ${(q.visual&&q.visual.ball_path)||""}`);
  const homeRisk=/三塁|本塁|ホーム|3B|outfield_home_throw/.test(story);
  const thirdRisk=/二塁|三塁|2B|3B/.test(story);
  if(homeRisk){
    return [
      {text:"本塁送球の後ろをバックアップする",score:3,explain:"外野ヒットで本塁返球の可能性がある時、ピッチャーは本塁送球の後ろをカバーして悪送球に備える。"},
      {text:"送球だけを考えておく",score:0,explain:"送球先だけでなく、後方カバーに入る必要がある。"},
      {text:"ボールの行方だけを見てから動く",score:1,explain:"確認は必要だが、見るだけではカバーが遅れる。"}
    ];
  }
  if(thirdRisk){
    return [
      {text:"三塁または本塁送球の後方バックアップに入る",score:3,explain:"外野ヒットでは、送球先の後方カバーに入って悪送球や次の進塁に備える。"},
      {text:"送球だけを考えておく",score:0,explain:"ピッチャーは外野から直接返球する役ではない。後方カバーが必要。"},
      {text:"ボールの行方だけを見てから動く",score:1,explain:"確認は必要だが、見るだけでは後方カバーが遅れる。"}
    ];
  }
  return [
    {text:"送球先の後方バックアップへ入る",score:3,explain:"外野ヒットでは、ピッチャーは返球先の後方カバーに入って次のプレイに備える。"},
    {text:"送球だけを考えておく",score:0,explain:"送球先だけでなく、後方カバーに入る必要がある。"},
    {text:"ボールの行方だけを見てから動く",score:1,explain:"確認は必要だが、見るだけではカバーが遅れる。"}
  ];
}
function isPitcherHomeCoverOnOneBounce(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/盗塁|二塁送球|三振|振り逃げ|キャッチャーフライ/.test(story))return false;
  if(/ワンバウンド|低い投球|前に落と|ワイルドピッチ|暴投後|本塁カバー|passed_ball/.test(story)){
    return /三塁|本塁|ワンバウンド|低い投球|前に落と|ワイルドピッチ|暴投後/.test(story);
  }
  return false;
}
function pitcherHomeCoverOnOneBounceChoices(q){
  return [
    {text:"すぐ本塁カバーに入る",score:3,explain:"投球が後ろや前にこぼれた時は、本塁で次のプレイに備える。"},
    {text:"走者だけを見る",score:1,explain:"走者確認は必要だが、本塁カバーが遅れる。"},
    {text:"捕手に任せる",score:0,explain:"本塁付近のカバーが必要。"}
  ];
}

function isAttackTwoOutForceBattedBall(q){
  if(!q || q.type!=="attack" || Number(q.outs)!==2)return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${q.stage||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/ワイルドピッチ|暴投|捕逸|パスボール|盗塁|牽制|偽投|三振|空振り|判定/.test(story))return false;
  if(/フライ|ライナー|fly|line/.test(story))return false;
  const isBatted=/打者が|打球|打った|ゴロ|バント|スクイズ|ground|bunt/.test(story);
  if(!isBatted)return false;
  return /一塁二塁|一・二塁|一塁二塁|一塁ランナー|走者一塁|ランナー一塁|満塁/.test(story) || q.stage==="1B";
}
function twoOutAttackForceRunChoices(q){
  return [
    {text:"全力で次のベースまで走る",score:3,explain:"フォースプレイでは次の塁へ進む。"},
    {text:"打球を見て止まる",score:0,explain:"止まるとフォースアウトになりやすい。"},
    {text:"元のベースへ戻る",score:0,explain:"フォースプレイでは戻れない。"}
  ];
}
function isAttackTwoOutFlyBall(q){
  if(!q || q.type!=="attack" || Number(q.outs)!==2)return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/ワイルドピッチ|暴投|捕逸|パスボール|盗塁|牽制|偽投|三振|空振り|判定/.test(story))return false;
  return /フライ|fly/.test(story);
}
function twoOutAttackFlyBallChoices(q){
  return [
    {text:"全力で次のベースまで走る",score:3,explain:"2アウトのフライでは、捕球されたら攻撃終了。戻らず次の塁へ走る。"},
    {text:"ハーフウェイで見る",score:0,explain:"2アウトでは捕球されても戻る必要はない。"},
    {text:"元のベースへ戻る",score:0,explain:"2アウトでは捕球後に戻る必要はない。"}
  ];
}
function isAttackInfieldLiner(q){
  if(!q || q.type!=="attack")return false;
  const theme=String(q.theme||"");
  const tag=normalizeSimilarText(`${q.ball_tag||""}`);
  const situation=normalizeSimilarText(`${q.situation||""}`);
  const prompt=normalizeSimilarText(`${q.prompt||""}`);
  const path=String((q.visual&&q.visual.ball_path)||"");
  const target=String((q.visual&&q.visual.target_position)||"");
  // 走路・ファウルライン系の "line" をライナー判定に誤認しない。
  if(/走路|foul_line|three_foot_lane|interference_avoid/.test(`${theme} ${tag} ${prompt}`))return false;
  const linerText = `${tag} ${situation} ${prompt}`;
  if(!(/ライナー/.test(linerText) || /liner|_line|line_drive/.test(path)))return false;
  return /ショート|セカンド|サード|ファースト|ピッチャー|short|second|third|first|pitcher|SS|2B|3B|1B/.test(`${linerText} ${path} ${target}`);
}
function attackInfieldLinerChoices(q){
  return [
    {text:"すぐベースに戻る",score:3,explain:"内野ライナーは捕球される可能性が高い。飛び出さず、すぐ元のベースに戻る。"},
    {text:"打球を見て進む",score:1,explain:"確認は必要だが、内野ライナーでは飛び出すと戻れなくなる。"},
    {text:"次のベースへ走る",score:0,explain:"捕球された時に戻れずアウトになりやすい。"}
  ];
}


function isFirstBaseStrongGapGrounder(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  return /一二塁間寄りの強い打球ファーストゴロ|一二塁間寄りの強いファーストゴロ/.test(story);
}
function firstBaseStrongGapGrounderChoices(q){
  return [
    {text:"セカンドに送球する",score:3,explain:"一塁走者を二塁でアウトにする判断。"},
    {text:"一塁を踏む",score:1,explain:"一塁でアウトは取れるが、二塁フォースアウトを狙える場面。"},
    {text:"一塁を踏んでから二塁に投げる",score:0,explain:"先に一塁を踏むと二塁はフォースではなくなり、アウトにしにくい。"}
  ];
}

function isFirstBaseForceOrTwoOutInfieldGrounder(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/外野|ヒット|長打|フライ|ライナー|暴投|ワイルドピッチ|捕逸|パスボール|牽制|盗塁/.test(story))return false;
  // ファーストゴロ・一二塁間の打球は、ファースト自身が打球処理する可能性があるため、
  // 一律で「送球を受けるだけ」の選択肢に補正しない。
  if(/ファーストゴロ|一二塁間|一塁線|first_grounder|first_base/.test(story))return false;
  const isInfield=/ゴロ|バント|スクイズ|grounder|bunt|second_grounder|short_grounder|third_grounder|pitcher_grounder|catcher_grounder/.test(story);
  if(!isInfield)return false;
  return Number(q.outs)===2 || /フォースアウトが狙える|フォースプレイ/.test(story);
}
function firstBaseForceOrTwoOutInfieldGrounderChoices(q){
  return [
    {text:"一塁ベースに入り捕球する準備をする",score:3,explain:"一塁でアウトを取れる準備をする。"},
    {text:"打者走者を見る",score:1,explain:"走者確認は必要だが、捕球準備が遅れる。"},
    {text:"特に何もしない",score:0,explain:"アウトを取る準備ができない。"}
  ];
}
function isFirstBaseOutfieldHit(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/クリーンナップ|強打者|守備位置指示|外野準備|cleanup|positioning|shift/.test(story))return false;
  if(/ゴロ|grounder|バント|スクイズ|暴投|ワイルドピッチ|捕逸|パスボール|牽制|盗塁/.test(story))return false;
  return /センター前|レフト前|ライト前|外野|ヒット|長打|left_single|center_single|right_single|left_center_gap|right_center_gap|left_line|right_line/.test(story);
}
function firstBaseOutfieldHitChoices(q){
  return [
    {text:"走者の走路をふさがない位置で次のプレイに備える",score:3,explain:"送球が来ていない時は、走路をふさがない位置で次のプレイに備える。"},
    {text:"一塁ベース上で送球を待つ",score:0,explain:"送球が来ていない時に走路をふさぐと走塁を妨げる可能性がある。"},
    {text:"打者走者を見る",score:1,explain:"確認は必要だが、位置取りも大切。"}
  ];
}
function isFirstBasePickoffReady(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  return /一塁牽制|first_base_pickoff_ready|pickoff_1b/.test(story) && /一塁走者|一塁/.test(story);
}
function firstBasePickoffReadyChoices(q){
  return [
    {text:"ランナーをタッチできる位置で構える",score:3,explain:"牽制に備えて、捕球後すぐタッチできる位置で構える。"},
    {text:"腕を伸ばして牽制を待つ",score:1,explain:"捕る準備はあるが、タッチが遅れやすい。"},
    {text:"ベースから一歩離れる",score:0,explain:"牽制球を受けにくくなる。"}
  ];
}


function isFirstBaseThirdRunnerBuntStance(q){
  if(!q || q.type!=="defense")return false;
  const id=String(q.id||"");
  if(["D012","D024","D048","D062","D074"].includes(id))return true;
  const story=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  return /三塁走者|三塁/.test(story) && /バントの構え|スクイズ気配|投手前に転がりそう/.test(story);
}
function firstBaseThirdRunnerBuntStanceChoices(q){
  if(Number(q.outs)===2){
    return [
      {text:"一塁ベースに入る準備をする",score:3,explain:"2アウトでは一塁でアウトを取る準備が必要。"},
      {text:"バントを捕りに走る",score:1,explain:"前に出る意識はあるが、一塁が空きやすい。"},
      {text:"一塁ベースの近くで様子を見る",score:0,explain:"準備が遅れる。"}
    ];
  }
  return [
    {text:"一塁ベース寄りに前進守備",score:3,explain:"バントに備えて前へ出る。"},
    {text:"一塁ベース後ろに下がる",score:0,explain:"バントへの対応が遅れる。"},
    {text:"一塁ベースの近くで様子を見る",score:1,explain:"様子を見るだけでは対応が遅れやすい。"}
  ];
}


function isTwoOutInfieldBuntOrSqueeze(q){
  if(!q || q.type!=="defense" || Number(q.outs)!==2)return false;
  if(!["P","1B","2B","SS","3B"].includes(STATE.position))return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${(q.visual&&q.visual.ball_path)||""} ${(q.visual&&q.visual.target_position)||""} ${(q.visual&&q.visual.ball_holder)||""}`);
  if(/ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|三振|キャッチャーフライ|捕手フライ|外野|ヒット|フライ|ライナー/.test(story))return false;
  return /バント|スクイズ|bunt|squeeze/.test(story);
}
function twoOutInfieldBuntOrSqueezeChoices(q){
  const target=String((q.visual&&q.visual.target_position)||grounderFieldingTarget(q)||"");
  const holder=String((q.visual&&q.visual.ball_holder)||"");
  const isFielder = target===STATE.position || holder===STATE.position;
  if(isFielder){
    return [
      {text:"一塁へ送球する",score:3,explain:"2アウトでは一塁でアウトを取る。"},
      {text:"本塁へ送球する",score:1,explain:"三塁走者は気になるが、2アウトでは一塁でアウトを取れる。"},
      {text:"三塁へ投げる",score:0,explain:"アウトを取る塁ではない。"}
    ];
  }
  return [
    {text:"一塁送球と声を出す",score:3,explain:"2アウトでは一塁でアウトを取る声かけをする。"},
    {text:"本塁送球と声を出す",score:1,explain:"三塁走者は気になるが、2アウトでは一塁アウトが基本。"},
    {text:"ボールの方向を見る",score:0,explain:"見るだけでは送球先の判断を助けられない。"}
  ];
}

function isTwoOutCatcherInfieldGrounder(q){
  if(!q || q.type!=="defense" || Number(q.outs)!==2)return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""} ${(q.visual&&q.visual.ball_holder)||""} ${(q.visual&&q.visual.target_position)||""}`);
  if(/外野|ヒット|長打|フライ|ライナー|バント|スクイズ|ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|キャッチャーフライ|捕手フライ/.test(story))return false;
  // 「セカンド前へのやや弱いゴロ」のように、ポジション名とゴロの間に説明が入る文も内野ゴロとして扱う。
  return /ゴロ|grounder|first_grounder|first_base|second_grounder|short_grounder|third_grounder|pitcher_grounder|catcher_grounder|unknown_to_pitcher/.test(story);
}
function twoOutCatcherInfieldGrounderChoices(q){
  return [
    {text:"ファーストへ送球するように声をかける",score:3,explain:"2アウトの内野ゴロでは、一塁でアウトを取る声かけが大切。"},
    {text:"ランナーだけを見る",score:1,explain:"走者確認も必要だが、送球先の声かけが遅れる。"},
    {text:"本塁へ投げるように声をかける",score:0,explain:"2アウトでは一塁でアウトを取る判断が基本。"}
  ];
}


function isCatcherHomeThrowReception(q){
  if(!q || q.type!=="defense")return false;
  if(Number(q.outs)!==0 && Number(q.outs)!==1)return false;
  if(!String(q.stage||"").includes("3B"))return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${(q.visual&&q.visual.ball_path)||""} ${(q.visual&&q.visual.ball_holder)||""} ${(q.visual&&q.visual.target_position)||""}`);
  if(/ワイルドピッチ|暴投|捕逸|パスボール|振り逃げ|三振|盗塁|牽制|偽投|ボーク|キャッチャーフライ|捕手フライ|本塁へ返球は来ていない|本塁を離れた|投球前|構え|守備位置指示|クリーンナップ/.test(story))return false;
  return /ゴロ|ヒット|フライ|返球|送球|バックホーム|本塁|ホーム|home_throw|single|grounder|fly/.test(story);
}
function catcherHomeThrowReceptionChoices(q){
  return [
    {text:"ホームベース前で送球に備える",score:3,explain:"送球を受けられる位置で走路をふさがない準備をする。"},
    {text:"ホームベースの後ろで待つ",score:1,explain:"送球には備えているが、捕球やタッチが遅れやすい。"},
    {text:"ホームベースの上で待つ",score:0,explain:"走路をふさぐ位置で待つのは避ける。"}
  ];
}


function grounderFieldingTarget(q){
  if(!q || q.type!=="defense")return "";
  const story=normalizeSimilarText(`${q.id||""} ${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""} ${(q.visual&&q.visual.target_position)||""} ${(q.visual&&q.visual.ball_holder)||""}`);
  if(/フライ|ライナー|ヒット|長打|バント|スクイズ|ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|三振|fly|line|single|bunt|squeeze|pickoff/.test(story))return "";
  const path=String((q.visual&&q.visual.ball_path)||"");
  // v431: unknown_to_pitcher は「方向未確定」なので、これだけでピッチャーゴロ扱いしない。
  // ゴロ/grounder/明示的な *_grounder の時だけ打球処理補正に進む。
  if(!/ゴロ|grounder|first_base/.test(story) && !/grounder|first_base/.test(path))return "";
  if(/first_second_gap_grounder/.test(path))return "1B";
  if(/second_short_gap_grounder/.test(path))return "SS";
  if(/third_short_gap_grounder/.test(path))return "3B";
  if(/second_grounder/.test(path))return "2B";
  if(/short_grounder/.test(path))return "SS";
  if(/third_grounder/.test(path))return "3B";
  if(/first_grounder|first_base/.test(path))return "1B";
  if(/pitcher_grounder|pitcher/.test(path))return "P";
  if(/catcher_grounder/.test(path))return "C";
  if(/セカンド(?:前|正面|への|ゴロ)|二塁手/.test(story))return "2B";
  if(/ショート(?:前|正面|方向|への|ゴロ)|遊撃/.test(story))return "SS";
  if(/サード(?:前|正面|方向|への|ゴロ)|三遊間|三塁線/.test(story))return "3B";
  if(/ファースト(?:前|正面|方向|への|ゴロ)|一二塁間|一塁線/.test(story))return "1B";
  if(/ピッチャー(?:前|正面|方向|への|ゴロ)|投手前|投手ゴロ/.test(story))return "P";
  if(/キャッチャー(?:前|正面|方向|への|ゴロ)|捕手前|捕手ゴロ/.test(story))return "C";
  return "";
}
function isOwnFielderGrounder(q){
  if(!q || q.type!=="defense")return false;
  const target=grounderFieldingTarget(q);
  if(!target || target!==STATE.position)return false;
  // ファーストは専用補正が多いため、未対応の時だけここに来る。
  return true;
}
function ownFielderGrounderChoices(q){
  const stage=String(q.stage||"");
  const outs=Number(q.outs);
  const story=normalizeSimilarText(`${q.id||""} ${q.ball_tag||""} ${q.situation||""} ${q.theme||""}`);
  if(outs===2){
    return [
      {text:"一塁へ送球する",score:3,explain:"2アウトでは一塁でアウトを取る判断。"},
      {text:"ランナーだけを見る",score:1,explain:"走者確認は必要だが、送球判断が遅れる。"},
      {text:"本塁へ送球する",score:0,explain:"2アウトでは本塁より一塁でアウトを取る。"}
    ];
  }
  if(stage.includes("3B") || /三塁走者|ランナー三塁|三塁ランナー|満塁/.test(story)){
    if(/本塁送球は難しい|本塁送球はきわどい|少しはじいて/.test(story)){
      return [
        {text:"一塁へ送球する",score:3,explain:"無理な本塁送球より確実なアウトを取る。"},
        {text:"本塁へ送球する",score:1,explain:"狙いはあるが、体勢が悪いと失点につながりやすい。"},
        {text:"送球だけを考える",score:0,explain:"走者とアウトカウントの確認が必要。"}
      ];
    }
    return [
      {text:"本塁へ送球する",score:3,explain:"三塁走者の本塁突入を防ぐ。"},
      {text:"一塁へ送球する",score:1,explain:"アウトは取れるが、失点を防ぐ判断も必要。"},
      {text:"三塁へ投げる",score:0,explain:"三塁走者は本塁へ向かっている。"}
    ];
  }
  if((stage.includes("1B2B") || /一塁二塁|一・二塁/.test(story)) && grounderFieldingTarget(q)==="3B"){
    return [
      {text:"打球を捕って三塁ベースを踏む",score:3,explain:"一・二塁で三塁手が捕ったゴロは、三塁がフォース。近い三塁ベースを踏むのが一番早く確実。"},
      {text:"二塁ランナーにタッチしにいく",score:1,explain:"タッチでもアウトにできる場合はあるが、フォースなら三塁ベースを踏む方が早い。"},
      {text:"一塁へ送球する",score:0,explain:"一塁でもアウトは狙えるが、近い三塁フォースを優先できる場面。"}
    ];
  }
  if(stage.includes("1B") || /一塁走者|ランナー一塁|一塁ランナー/.test(story)){
    return [
      {text:"二塁へ送球する",score:3,explain:"一塁走者を二塁でアウトにする判断。"},
      {text:"一塁へ送球する",score:1,explain:"打者走者のアウトは取れる。"},
      {text:"一塁ランナーを追いかける",score:0,explain:"送球でアウトを狙う場面。"}
    ];
  }
  return [
    {text:"一塁へ送球する",score:3,explain:"捕球後は一塁でアウトを取る。"},
    {text:"送球だけを考える",score:1,explain:"捕球と走者確認も必要。"},
    {text:"ボールの方向へ寄っていく",score:0,explain:"自分が捕球した後の送球判断が必要。"}
  ];
}


function isOtherInfielderTwoOutGrounder(q){
  if(!q || q.type!=="defense" || Number(q.outs)!==2)return false;
  if(!["P","2B","SS","3B"].includes(STATE.position))return false;
  const target=grounderFieldingTarget(q);
  if(!target || !["P","1B","2B","SS","3B"].includes(target))return false;
  if(target===STATE.position)return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/フライ|ライナー|ヒット|長打|バント|スクイズ|ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|三振/.test(story))return false;
  return /ゴロ|grounder|first_base|unknown_to_pitcher/.test(story);
}
function forceThrowBaseForTwoOutGrounder(q,target){
  const grade=Number((STATE&&STATE.grade)||q.grade||0);
  // 4年生以下は判断を複雑にしないため、2アウトの他内野手処理は一塁送球の声出しに統一。
  if(grade && grade<=4)return "一塁";
  const stage=String(q.stage||"");
  const has1=stage.includes("1B");
  const has2=stage.includes("2B");
  const has3=stage.includes("3B");
  if(has1&&has2&&target==="3B")return "三塁";
  if(has1&&(target==="2B"||target==="SS"))return "二塁";
  if(has1&&has2&&has3&&target==="P")return "本塁";
  // 近くにフォースアウトできる走者がいない時は、打者走者を一塁でアウトにする。
  return "一塁";
}
function otherInfielderTwoOutGrounderChoices(q){
  const target=grounderFieldingTarget(q);
  const base=forceThrowBaseForTwoOutGrounder(q,target);
  if(base==="一塁"){
    return [
      {text:"一塁送球と声を出す",score:3,explain:"2アウトでは一塁でアウトを取る声かけをする。"},
      {text:"ランナーだけを見る",score:1,explain:"走者確認は必要だが、送球先の声が遅れる。"},
      {text:"近くの塁だけ見る",score:0,explain:"アウトを取る塁を伝える必要がある。"}
    ];
  }
  return [
    {text:`${base}送球と声を出す`,score:3,explain:"フォースアウトになる塁を伝える。"},
    {text:"一塁送球と声を出す",score:1,explain:"アウトは狙えるが、近くのフォースアウトも確認したい。"},
    {text:"ランナーだけを見る",score:0,explain:"送球先を伝えないと判断が遅れる。"}
  ];
}

function isEmptyBaseStage(q){
  const stage=String(q.stage||"").toLowerCase();
  const runners=(q.visual&&Array.isArray(q.visual.runners))?q.visual.runners:[];
  if(stage==="none" || stage==="" || stage==="runner_none")return true;
  return runners.length===0 && !/[123]B/.test(String(q.stage||""));
}
function isOtherInfielderNoOutEmptyGrounder(q){
  if(!q || q.type!=="defense" || Number(q.outs)!==0)return false;
  if(!["P","1B","2B","SS","3B"].includes(STATE.position))return false;
  if(!isEmptyBaseStage(q))return false;
  const target=grounderFieldingTarget(q);
  if(!target || !["P","1B","2B","SS","3B"].includes(target))return false;
  if(target===STATE.position)return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.ball_tag||""} ${q.situation||""} ${q.theme||""} ${(q.visual&&q.visual.ball_path)||""}`);
  if(/フライ|ライナー|ヒット|長打|バント|スクイズ|ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|三振/.test(story))return false;
  return /ゴロ|grounder|first_base|unknown_to_pitcher/.test(story);
}
function otherInfielderNoOutEmptyGrounderChoices(q){
  return [
    {text:"一塁送球と声を出す",score:3,explain:"走者なしの内野ゴロは、一塁でアウトを取る声かけをする。"},
    {text:"打球だけを見る",score:1,explain:"確認は必要だが、送球先の声が遅れる。"},
    {text:"近くの塁だけ見る",score:0,explain:"アウトを取る塁を伝える必要がある。"}
  ];
}



function isShortCoverSecondOnFirstBaseOverthrow(q){
  if(!q || q.type!=="defense" || STATE.position!=="SS")return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${(q.visual&&q.visual.ball_path)||""}`);
  return /一塁.*悪送球|一塁方向.*それた|一塁へ送球.*それた/.test(story);
}
function shortCoverSecondOnFirstBaseOverthrowChoices(q){
  return [
    {text:"二塁に入る",score:3,explain:"一塁への悪送球では、打者走者が二塁を狙う可能性があるため二塁に入る。"},
    {text:"ボールの方向へ寄る",score:1,explain:"カバー意識はあるが、二塁が空くと次の進塁を止めにくい。"},
    {text:"ランナーだけを見る",score:0,explain:"ボールと次の進塁先を確認する必要がある。"}
  ];
}


function isShortCoverThirdGrounder(q){
  if(!q || q.type!=="defense" || STATE.position!=="SS")return false;
  const visual=q.visual||{};
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${visual.ball_path||""} ${visual.ball_holder||""} ${visual.target_position||""}`);
  if(/バント|スクイズ|ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|三振/.test(story))return false;
  const target=String(visual.target_position||grounderFieldingTarget(q)||"");
  const path=String(visual.ball_path||"").toLowerCase();
  const holder=String(visual.ball_holder||"");
  return /サードゴロ|三塁.*ゴロ|サード.*打球|third_grounder/.test(story) || target==="3B" || holder==="3B" || /third|3b/.test(path);
}
function shortCoverThirdGrounderChoices(q){
  return [
    {text:"サードゴロをカバー",score:3,explain:"サードへの打球では、ショートは三塁側のカバーに動く。"},
    {text:"二塁ベースに入る",score:1,explain:"二塁も大切だが、この打球ではサード側のカバーが優先。"},
    {text:"ボールの方向を見る",score:0,explain:"見るだけではカバーが遅れる。"}
  ];
}

function isSecondBaseCoverFirstGrounder(q){
  if(!q || q.type!=="defense" || STATE.position!=="2B")return false;
  const visual=q.visual||{};
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${visual.ball_path||""} ${visual.ball_holder||""} ${visual.target_position||""}`);
  if(/バント|スクイズ|ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|三振/.test(story))return false;
  const target=String(visual.target_position||grounderFieldingTarget(q)||"");
  const path=String(visual.ball_path||"").toLowerCase();
  const holder=String(visual.ball_holder||"");
  return /ファーストゴロ|一塁.*ゴロ|一二塁間|first_grounder|first_base/.test(story) || target==="1B" || holder==="1B" || /first|1b/.test(path);
}
function secondBaseCoverFirstGrounderChoices(q){
  return [
    {text:"ファーストゴロをカバー",score:3,explain:"ファースト側の打球では、セカンドは打球方向をカバーする。"},
    {text:"二塁ベースに入る",score:1,explain:"二塁ベースはショートが入るため、この場面ではカバーが優先。"},
    {text:"ボールの方向を見る",score:0,explain:"見るだけではカバーが遅れる。"}
  ];
}

function isOutfieldOrSideBallForMiddleInfielderSecondBaseCover(q){
  if(!q || q.type!=="defense")return false;
  if(!["2B","SS"].includes(STATE.position))return false;
  const visual=q.visual||{};
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${visual.ball_path||""} ${visual.ball_holder||""} ${visual.target_position||""}`);
  if(/multi_runner_force_play|フォースプレイ|フォースアウト|満塁|バント|スクイズ|ワイルドピッチ|暴投|捕逸|パスボール|牽制|盗塁|ボーク|振り逃げ|三振|キャッチャーフライ|捕手フライ|ワンバウンド/.test(story))return false;
  if(!/ゴロ|ヒット|フライ|ライナー|打球|grounder|single|line|fly|gap/.test(story))return false;
  const target=String(visual.target_position||grounderFieldingTarget(q)||"");
  const path=String(visual.ball_path||"");
  const ballHolder=String(visual.ball_holder||"");
  // 選択中ポジションに打球が来ている場合は、二塁カバー補正を絶対に使わない。
  // 例: ショートのショートゴロで「二塁ベースに入る」が出る誤判定を防ぐ。
  if(target===STATE.position || ballHolder===STATE.position)return false;
  if(STATE.position==="SS"){
    if(isShortLeftFieldRelay(q))return false;
    if(isShortCoverThirdGrounder(q))return false;
    return /ライト|ファースト|セカンド|right|first|second/.test(story) || ["RF","1B","2B"].includes(target) || ["RF","1B","2B"].includes(ballHolder) || /right|first|second|1b|2b/.test(path.toLowerCase());
  }
  if(STATE.position==="2B"){
    if(isSecondBaseRightFieldRelay(q))return false;
    if(isSecondBaseCoverFirstGrounder(q))return false;
    return /レフト|サード|ショート|三塁|left|third|short/.test(story) || ["LF","3B","SS"].includes(target) || ["LF","3B","SS"].includes(ballHolder) || /left|third|short|3b|ss/.test(path.toLowerCase());
  }
  return false;
}
function middleInfielderSecondBaseCoverChoices(q){
  return [
    {text:"二塁ベースに入る",score:3,explain:"反対側の打球では二塁ベースのカバーに入る。"},
    {text:"ボールの方向を見る",score:1,explain:"確認は必要だが、二塁カバーが遅れる。"},
    {text:"近くのベースに残る",score:0,explain:"必要なベースカバーに入れない。"}
  ];
}


function isSecondBaseRightFieldRelay(q){
  if(!q || q.type!=="defense" || STATE.position!=="2B")return false;
  const v=q.visual||{};
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${v.ball_path||""} ${v.ball_holder||""} ${v.target_position||""}`);
  if(/クリーンナップ|強打者|打者|右バッター|左バッター|守備位置指示|外野の位置|外野準備|positioning|cleanup/.test(story))return false;
  if(/フライ/.test(story))return false;
  return /ライト線|ライト前|right_single|right_line|right_center_gap/.test(story);
}
function secondBaseRightFieldRelayChoices(q){
  return [
    {text:"ライトからの返球を受ける中継位置に入る",score:3,explain:"ライト線・ライト前へのヒットでは、セカンドは二塁ベースに入るよりも、ライトから内野へ返す中継に入るのが基本。"},
    {text:"二塁ベース付近で走者と送球を確認する",score:1,explain:"確認は必要だが、ライトからの返球に対しては中継に入る準備を優先する。"},
    {text:"二塁ベースに入ったまま待つ",score:0,explain:"ライトからの返球や次の進塁に対応する中継が遅れる。"}
  ];
}
function isShortLeftFieldRelay(q){
  if(!q || q.type!=="defense" || STATE.position!=="SS")return false;
  const v=q.visual||{};
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${v.ball_path||""} ${v.ball_holder||""} ${v.target_position||""}`);
  if(/クリーンナップ|強打者|打者|右バッター|左バッター|守備位置指示|外野の位置|外野準備|positioning|cleanup/.test(story))return false;
  if(/フライ/.test(story))return false;
  return /レフト前|レフト線|left_single|left_line|left_center_gap/.test(story);
}
function shortLeftFieldRelayChoices(q){
  return [
    {text:"レフトからの返球を受ける中継位置に入る",score:3,explain:"レフト前・レフト線へのヒットでは、ショートがレフトからの返球を受ける中継に入るのが基本。"},
    {text:"二塁ベース付近で走者と送球を確認する",score:1,explain:"確認は必要だが、レフトからの返球に対しては中継に入る準備を優先する。"},
    {text:"ベース付近に残って様子を見る",score:0,explain:"レフトからの返球や次の進塁に対応する中継が遅れる。"}
  ];
}


function isCuratedForcePlayQuestion(q){
  if(!q || q.type!=="defense")return false;
  const story=normalizeSimilarText(`${q.id||""} ${q.theme||""} ${q.ball_tag||""} ${q.situation||""} ${q.prompt||""} ${q.stage||""}`);
  return /multi_runner_force_play|フォースプレイ|フォースアウト|満塁|一塁二塁|一・二塁|二塁三塁|二・三塁/.test(story);
}
function curatedPositionChoices(q){
  return (q.choices_by_position&&q.choices_by_position[STATE.position])||[];
}

function dataChoicesForCurrentPosition(q){
  if(!q)return [];
  if(q.type==="defense"){
    return (q.choices_by_position&&q.choices_by_position[STATE.position])||[];
  }
  return q.choices||[];
}
function getFallbackCorrectedChoices(q){
  // v436: 補正は「データに選択肢がない問題」の最後の救済だけに限定する。
  // 既存の問題データを上書きしないため、通常プレイではここに入らない。
  if(isAttackTwoOutFlyBall(q))return twoOutAttackFlyBallChoices(q);
  if(isAttackInfieldLiner(q))return attackInfieldLinerChoices(q);
  if(isAttackTwoOutForceBattedBall(q))return twoOutAttackForceRunChoices(q);
  if(q.type==="defense"){
    if(isTwoOutInfieldBuntOrSqueeze(q))return twoOutInfieldBuntOrSqueezeChoices(q);
    if(STATE.position==="C" && isCatcherHomeThrowReception(q))return catcherHomeThrowReceptionChoices(q);
    if(STATE.position==="C" && isTwoOutCatcherInfieldGrounder(q))return twoOutCatcherInfieldGrounderChoices(q);
    if(STATE.position==="1B" && isFirstBaseThirdRunnerBuntStance(q))return firstBaseThirdRunnerBuntStanceChoices(q);
    if(STATE.position==="1B" && isFirstBasePickoffReady(q))return firstBasePickoffReadyChoices(q);
    if(STATE.position==="1B" && isFirstBaseStrongGapGrounder(q))return firstBaseStrongGapGrounderChoices(q);
    if(STATE.position==="1B" && isFirstBaseForceOrTwoOutInfieldGrounder(q))return firstBaseForceOrTwoOutInfieldGrounderChoices(q);
    if(STATE.position==="1B" && isFirstBaseOutfieldHit(q))return firstBaseOutfieldHitChoices(q);
    if(STATE.position==="P" && isPitcherHomeCoverOnOneBounce(q))return pitcherHomeCoverOnOneBounceChoices(q);
    if(STATE.position==="P" && isPitcherFirstGrounderCover(q))return pitcherFirstGrounderCoverChoices(q);
    if(STATE.position==="P" && isPitcherFirstBaseBuntCover(q))return pitcherFirstBaseBuntCoverChoices(q);
    if(STATE.position==="P" && isTwoOutPitcherFrontGrounderOrBunt(q))return twoOutPitcherFrontGrounderOrBuntChoices(q);
    if(STATE.position==="P" && isTwoOutOrdinaryInfieldGrounder(q))return twoOutPitcherGrounderChoices(q);
    if(STATE.position==="P" && isTwoOutOutfieldHitForPitcher(q))return twoOutPitcherOutfieldHitChoices(q);
    if(isOwnFielderGrounder(q))return ownFielderGrounderChoices(q);
    if(isShortCoverSecondOnFirstBaseOverthrow(q))return shortCoverSecondOnFirstBaseOverthrowChoices(q);
    if(isSecondBaseRightFieldRelay(q))return secondBaseRightFieldRelayChoices(q);
    if(isShortLeftFieldRelay(q))return shortLeftFieldRelayChoices(q);
    if(isShortCoverThirdGrounder(q))return shortCoverThirdGrounderChoices(q);
    if(isSecondBaseCoverFirstGrounder(q))return secondBaseCoverFirstGrounderChoices(q);
    if(isOutfieldOrSideBallForMiddleInfielderSecondBaseCover(q))return middleInfielderSecondBaseCoverChoices(q);
    if(isOtherInfielderTwoOutGrounder(q))return otherInfielderTwoOutGrounderChoices(q);
    if(isOtherInfielderNoOutEmptyGrounder(q))return otherInfielderNoOutEmptyGrounderChoices(q);
  }
  return [];
}

// v456: 2アウト時だけ有効な内野ゴロ声かけ補正。
// 通常はデータ選択肢を尊重するが、アウトカウント未固定問題が2アウトで出題された場合だけ、
// 「本塁送球系」の古い選択肢を一塁送球声かけへ切り替える。
function v456PosFromBallPath(play){
  const map={
    pitcher_grounder:"P",
    first_grounder:"1B",
    first_base:"1B",
    first_second_gap_grounder:"1B",
    second_grounder:"2B",
    second_short_gap_grounder:"SS",
    short_grounder:"SS",
    third_short_gap_grounder:"3B",
    third_grounder:"3B"
  };
  return map[play]||"";
}
function v456Runners(q){
  return new Set(((q&&q.visual&&q.visual.runners)||[]).map(String));
}
function v456IsNormalInfieldGrounder(q){
  if(!q || q.type!=="defense")return false;
  const s=[q.ball_tag,q.situation,q.prompt,q.theme,(q.visual&&q.visual.play)||""].join(" ");
  if(/スクイズ|バント|ヒット|フライ|ライナー|牽制|悪送球/.test(s))return false;
  const f=v456PosFromBallPath(q.visual&&q.visual.ball_path);
  return ["P","1B","2B","SS","3B"].includes(f);
}
function v456TwoOutFirstThrowVoiceChoices(q,dataChoices){
  if(!q || q.type!=="defense")return null;
  if(Number(q.outs)!==2)return null;
  if(!v456IsNormalInfieldGrounder(q))return null;
  const f=v456PosFromBallPath(q.visual&&q.visual.ball_path);
  if(!f || STATE.position===f)return null;
  if(!["P","1B","2B","SS","3B"].includes(STATE.position))return null;
  const r=v456Runners(q);
  // 一塁走者がいる時は二塁フォース等の別ルールが優先。ここでは扱わない。
  if(r.has("1B"))return null;
  // 2アウトで一塁走者がいない通常内野ゴロは、一塁でアウトを取れば攻撃終了。
  // 既に同じ正解なら何もしない。
  if(dataChoices && dataChoices.some(c=>c&&c.score===3&&c.text==="一塁へ送球するよう声をかける"))return null;
  return [
    {
      text:"一塁へ送球するよう声をかける",
      score:3,
      explain:"2アウトの内野ゴロでは、一塁でアウトを取れば攻撃終了。打球を処理する内野手に一塁へ送球するよう声をかける。"
    },
    {
      text:"ランナーだけを見る",
      score:1,
      explain:"走者確認も必要だが、2アウトでは送球先を声で伝えることが大切。"
    },
    {
      text:"ボールの方向へ寄っていく",
      score:0,
      explain:"自分の役割を考えずに動くと、送球先の声かけが遅れる。"
    }
  ];
}

function getChoices(q){
  const dataChoices=dataChoicesForCurrentPosition(q);
  // v456: アウトカウント未固定の内野ゴロが2アウトで出た時だけ、声かけ判断を動的に補正する。
  const v456Voice=v456TwoOutFirstThrowVoiceChoices(q,dataChoices);
  if(v456Voice)return v456Voice;
  // v436: authored data is authoritative. Never overwrite existing choices.
  if(dataChoices && dataChoices.length>0)return dataChoices;
  // Only fallback-only / missing-choice questions may use correction logic.
  if(q && q.correction_policy==="fallback_only")return getFallbackCorrectedChoices(q);
  return [];
}
function promptText(q){return q.prompt || (q.type==="defense"?"この場面で、君の守備位置ならどう動く？":"この場面で、君ならどう判断する？")}
function escapeHtml(s){return String(s??"").replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]))}

function shouldUseRuby(){
  return Number(STATE.grade||0)<=2;
}
const RUBY_ENTRIES=[
  ["進めました", "<ruby>進<rt>すす</rt></ruby>めました"],
  ["進めます", "<ruby>進<rt>すす</rt></ruby>めます"],
  ["進めた", "<ruby>進<rt>すす</rt></ruby>めた"],
  ["進めて", "<ruby>進<rt>すす</rt></ruby>めて"],
  ["進める", "<ruby>進<rt>すす</rt></ruby>める"],
  ["外側", "<ruby>外側<rt>そとがわ</rt></ruby>"],
  ["線の外側", "<ruby>線<rt>せん</rt></ruby>の<ruby>外側<rt>そとがわ</rt></ruby>"],
  ["続ける", "<ruby>続<rt>つづ</rt></ruby>ける"],
  ["続けて", "<ruby>続<rt>つづ</rt></ruby>けて"],
  ["続けた", "<ruby>続<rt>つづ</rt></ruby>けた"],
  ["続けます", "<ruby>続<rt>つづ</rt></ruby>けます"],
  ["続けました", "<ruby>続<rt>つづ</rt></ruby>けました"],
  ["捕られた", "<ruby>捕<rt>と</rt></ruby>られた"],
  ["捕られて", "<ruby>捕<rt>と</rt></ruby>られて"],
  ["捕られる", "<ruby>捕<rt>と</rt></ruby>られる"],
  ["捕られ", "<ruby>捕<rt>と</rt></ruby>られ"],
  ["線の内側", "<ruby>線<rt>せん</rt></ruby>の<ruby>内側<rt>うちがわ</rt></ruby>"],
  ["内側", "<ruby>内側<rt>うちがわ</rt></ruby>"],
  ["打たないで", "<ruby>打<rt>う</rt></ruby>たないで"],
  ["打たない", "<ruby>打<rt>う</rt></ruby>たない"],
  ["打たずに", "<ruby>打<rt>う</rt></ruby>たずに"],
  ["打たず", "<ruby>打<rt>う</rt></ruby>たず"],
  ["直接走る", "<ruby>直接<rt>ちょくせつ</rt></ruby><ruby>走<rt>はし</rt></ruby>る"],
  ["直接", "<ruby>直接<rt>ちょくせつ</rt></ruby>"],
  ["球が後ろへ転がりました", "<ruby>球<rt>たま</rt></ruby>が<ruby>後<rt>うし</rt></ruby>ろへ<ruby>転<rt>ころ</rt></ruby>がりました"],
  ["転がりました", "<ruby>転<rt>ころ</rt></ruby>がりました"],
  ["転がります", "<ruby>転<rt>ころ</rt></ruby>がります"],
  ["転がって", "<ruby>転<rt>ころ</rt></ruby>がって"],
  ["転がった", "<ruby>転<rt>ころ</rt></ruby>がった"],
  ["転がる", "<ruby>転<rt>ころ</rt></ruby>がる"],
  ["少し先まで進みました", "<ruby>少<rt>すこ</rt></ruby>し<ruby>先<rt>さき</rt></ruby>まで<ruby>進<rt>すす</rt></ruby>みました"],
  ["進みました", "<ruby>進<rt>すす</rt></ruby>みました"],
  ["進みます", "<ruby>進<rt>すす</rt></ruby>みます"],
  ["進み", "<ruby>進<rt>すす</rt></ruby>み"],
  ["投げられた球", "<ruby>投<rt>な</rt></ruby>げられた<ruby>球<rt>たま</rt></ruby>"],
  ["球が", "<ruby>球<rt>たま</rt></ruby>が"],
  ["球を", "<ruby>球<rt>たま</rt></ruby>を"],
  ["球は", "<ruby>球<rt>たま</rt></ruby>は"],
  ["球に", "<ruby>球<rt>たま</rt></ruby>に"],
  ["球の", "<ruby>球<rt>たま</rt></ruby>の"],
  ["選手", "<ruby>選手<rt>せんしゅ</rt></ruby>"],
  ["地面", "<ruby>地面<rt>じめん</rt></ruby>"],
  ["投げられたボール", "<ruby>投<rt>な</rt></ruby>げられたボール"],
  ["投げられた", "<ruby>投<rt>な</rt></ruby>げられた"],
  ["投げられて", "<ruby>投<rt>な</rt></ruby>げられて"],
  ["投げられる", "<ruby>投<rt>な</rt></ruby>げられる"],
  ["ボールを打ちました", "ボールを<ruby>打<rt>う</rt></ruby>ちました"],
  ["打ちました", "<ruby>打<rt>う</rt></ruby>ちました"],
  ["打ちます", "<ruby>打<rt>う</rt></ruby>ちます"],
  ["打ち", "<ruby>打<rt>う</rt></ruby>ち"],
  ["ベースへ着きました", "ベースへ<ruby>着<rt>つ</rt></ruby>きました"],
  ["ベースに着きました", "ベースに<ruby>着<rt>つ</rt></ruby>きました"],
  ["一塁に着きました", "<ruby>一塁<rt>いちるい</rt></ruby>に<ruby>着<rt>つ</rt></ruby>きました"],
  ["二塁に着きました", "<ruby>二塁<rt>にるい</rt></ruby>に<ruby>着<rt>つ</rt></ruby>きました"],
  ["三塁に着きました", "<ruby>三塁<rt>さんるい</rt></ruby>に<ruby>着<rt>つ</rt></ruby>きました"],
  ["本塁に着きました", "<ruby>本塁<rt>ほんるい</rt></ruby>に<ruby>着<rt>つ</rt></ruby>きました"],
  ["着きました", "<ruby>着<rt>つ</rt></ruby>きました"],
  ["着きます", "<ruby>着<rt>つ</rt></ruby>きます"],
  ["着いた", "<ruby>着<rt>つ</rt></ruby>いた"],
  ["着いて", "<ruby>着<rt>つ</rt></ruby>いて"],
  ["着く", "<ruby>着<rt>つ</rt></ruby>く"],
  ["一塁を目指して走る", "<ruby>一塁<rt>いちるい</rt></ruby>を<ruby>目指<rt>めざ</rt></ruby>して<ruby>走<rt>はし</rt></ruby>る"],
  ["二塁を目指して走る", "<ruby>二塁<rt>にるい</rt></ruby>を<ruby>目指<rt>めざ</rt></ruby>して<ruby>走<rt>はし</rt></ruby>る"],
  ["三塁を目指して走る", "<ruby>三塁<rt>さんるい</rt></ruby>を<ruby>目指<rt>めざ</rt></ruby>して<ruby>走<rt>はし</rt></ruby>る"],
  ["本塁を目指して走る", "<ruby>本塁<rt>ほんるい</rt></ruby>を<ruby>目指<rt>めざ</rt></ruby>して<ruby>走<rt>はし</rt></ruby>る"],
  ["目指して", "<ruby>目指<rt>めざ</rt></ruby>して"],
  ["目指した", "<ruby>目指<rt>めざ</rt></ruby>した"],
  ["目指す", "<ruby>目指<rt>めざ</rt></ruby>す"],
  ["目指し", "<ruby>目指<rt>めざ</rt></ruby>し"],
  ["走る", "<ruby>走<rt>はし</rt></ruby>る"],
  ["走って", "<ruby>走<rt>はし</rt></ruby>って"],
  ["走った", "<ruby>走<rt>はし</rt></ruby>った"],
  ["試合", "<ruby>試合<rt>しあい</rt></ruby>"],
  ["守ります", "<ruby>守<rt>まも</rt></ruby>ります"],
  ["守りました", "<ruby>守<rt>まも</rt></ruby>りました"],
  ["守って", "<ruby>守<rt>まも</rt></ruby>って"],
  ["守った", "<ruby>守<rt>まも</rt></ruby>った"],
  ["守っている", "<ruby>守<rt>まも</rt></ruby>っている"],
  ["守ってい", "<ruby>守<rt>まも</rt></ruby>ってい"],
  ["守り", "<ruby>守<rt>まも</rt></ruby>り"],
  ["守る", "<ruby>守<rt>まも</rt></ruby>る"],
  ["黄色い矢印", "<ruby>黄色<rt>きいろ</rt></ruby>い<ruby>矢印<rt>やじるし</rt></ruby>"],
  ["黄色い", "<ruby>黄色<rt>きいろ</rt></ruby>い"],
  ["矢印", "<ruby>矢印<rt>やじるし</rt></ruby>"],
  ["塁に着いた", "<ruby>塁<rt>るい</rt></ruby>に<ruby>着<rt>つ</rt></ruby>いた"],
  ["動き", "<ruby>動<rt>うご</rt></ruby>き"],
  ["動く", "<ruby>動<rt>うご</rt></ruby>く"],
  ["動いて", "<ruby>動<rt>うご</rt></ruby>いて"],
  ["動いた", "<ruby>動<rt>うご</rt></ruby>いた"],
  ["基本問題", "<ruby>基本問題<rt>きほんもんだい</rt></ruby>"],
  ["基本動作", "<ruby>基本動作<rt>きほんどうさ</rt></ruby>"],
  ["問題", "<ruby>問題<rt>もんだい</rt></ruby>"],
  ["選択肢", "<ruby>選択肢<rt>せんたくし</rt></ruby>"],
  ["正解", "<ruby>正解<rt>せいかい</rt></ruby>"],
  ["不正解", "<ruby>不正解<rt>ふせいかい</rt></ruby>"],
  ["解説", "<ruby>解説<rt>かいせつ</rt></ruby>"],
  ["野球", "<ruby>野球<rt>やきゅう</rt></ruby>"],
  ["低く速い打球", "<ruby>低<rt>ひく</rt></ruby>く<ruby>速<rt>はや</rt></ruby>い<ruby>打球<rt>だきゅう</rt></ruby>"],
  ["低く速く", "<ruby>低<rt>ひく</rt></ruby>く<ruby>速<rt>はや</rt></ruby>く"],
  ["低く速い", "<ruby>低<rt>ひく</rt></ruby>く<ruby>速<rt>はや</rt></ruby>い"],
  ["低く", "<ruby>低<rt>ひく</rt></ruby>く"],
  ["低い", "<ruby>低<rt>ひく</rt></ruby>い"],
  ["速く", "<ruby>速<rt>はや</rt></ruby>く"],
  ["速い", "<ruby>速<rt>はや</rt></ruby>い"],
  ["打者走者", "<ruby>打者走者<rt>だしゃそうしゃ</rt></ruby>"],
  ["本打者走者", "<ruby>本打者走者<rt>ほんだしゃそうしゃ</rt></ruby>"],
  ["守る場所", "<ruby>守<rt>まも</rt></ruby>る<ruby>場所<rt>ばしょ</rt></ruby>"],
  ["場所", "<ruby>場所<rt>ばしょ</rt></ruby>"],
  ["名前", "<ruby>名前<rt>なまえ</rt></ruby>"],
  ["相手チーム", "<ruby>相手<rt>あいて</rt></ruby>チーム"],
  ["三振", "<ruby>三振<rt>さんしん</rt></ruby>"],
  ["空振り", "<ruby>空振<rt>からぶ</rt></ruby>り"],
  ["見逃し", "<ruby>見逃<rt>みのが</rt></ruby>し"],
  ["振り逃げ", "<ruby>振<rt>ふ</rt></ruby>り<ruby>逃<rt>に</rt></ruby>げ"],
  ["内野手", "<ruby>内野手<rt>ないやしゅ</rt></ruby>"],
  ["外野手", "<ruby>外野手<rt>がいやしゅ</rt></ruby>"],
  ["走塁", "<ruby>走塁<rt>そうるい</rt></ruby>"],
  ["進塁", "<ruby>進塁<rt>しんるい</rt></ruby>"],
  ["盗塁", "<ruby>盗塁<rt>とうるい</rt></ruby>"],
  ["守備位置", "<ruby>守備位置<rt>しゅびいち</rt></ruby>"],
  ["守備", "<ruby>守備<rt>しゅび</rt></ruby>"],
  ["捕手", "<ruby>捕手<rt>ほしゅ</rt></ruby>"],
  ["投手", "<ruby>投手<rt>とうしゅ</rt></ruby>"],
  ["走者", "<ruby>走者<rt>そうしゃ</rt></ruby>"],
  ["打者", "<ruby>打者<rt>だしゃ</rt></ruby>"],
  ["打球", "<ruby>打球<rt>だきゅう</rt></ruby>"],
  ["投球", "<ruby>投球<rt>とうきゅう</rt></ruby>"],
  ["送球", "<ruby>送球<rt>そうきゅう</rt></ruby>"],
  ["捕球", "<ruby>捕球<rt>ほきゅう</rt></ruby>"],
  ["判定", "<ruby>判定<rt>はんてい</rt></ruby>"],
  ["得点", "<ruby>得点<rt>とくてん</rt></ruby>"],
  ["失点", "<ruby>失点<rt>しってん</rt></ruby>"],
  ["条件", "<ruby>条件<rt>じょうけん</rt></ruby>"],
  ["成立", "<ruby>成立<rt>せいりつ</rt></ruby>"],
  ["確認", "<ruby>確認<rt>かくにん</rt></ruby>"],
  ["判断", "<ruby>判断<rt>はんだん</rt></ruby>"],
  ["安全", "<ruby>安全<rt>あんぜん</rt></ruby>"],
  ["必要", "<ruby>必要<rt>ひつよう</rt></ruby>"],
  ["方向", "<ruby>方向<rt>ほうこう</rt></ruby>"],
  ["内野", "<ruby>内野<rt>ないや</rt></ruby>"],
  ["外野", "<ruby>外野<rt>がいや</rt></ruby>"],
  ["前進", "<ruby>前進<rt>ぜんしん</rt></ruby>"],
  ["正面", "<ruby>正面<rt>しょうめん</rt></ruby>"],
  ["範囲", "<ruby>範囲<rt>はんい</rt></ruby>"],
  ["位置", "<ruby>位置<rt>いち</rt></ruby>"],
  ["相手", "<ruby>相手<rt>あいて</rt></ruby>"],
  ["自分", "<ruby>自分<rt>じぶん</rt></ruby>"],
  ["場面", "<ruby>場面<rt>ばめん</rt></ruby>"],
  ["本塁", "<ruby>本塁<rt>ほんるい</rt></ruby>"],
  ["一塁", "<ruby>一塁<rt>いちるい</rt></ruby>"],
  ["二塁", "<ruby>二塁<rt>にるい</rt></ruby>"],
  ["三塁", "<ruby>三塁<rt>さんるい</rt></ruby>"],
  ["投げる", "<ruby>投<rt>な</rt></ruby>げる"],
  ["投げて", "<ruby>投<rt>な</rt></ruby>げて"],
  ["投げた", "<ruby>投<rt>な</rt></ruby>げた"],
  ["打つ", "<ruby>打<rt>う</rt></ruby>つ"],
  ["打って", "<ruby>打<rt>う</rt></ruby>って"],
  ["打った", "<ruby>打<rt>う</rt></ruby>った"],
  ["打てる", "<ruby>打<rt>う</rt></ruby>てる"],
  ["捕る", "<ruby>捕<rt>と</rt></ruby>る"],
  ["捕って", "<ruby>捕<rt>と</rt></ruby>って"],
  ["捕った", "<ruby>捕<rt>と</rt></ruby>った"],
  ["捕れる", "<ruby>捕<rt>と</rt></ruby>れる"],
  ["取る", "<ruby>取<rt>と</rt></ruby>る"],
  ["取って", "<ruby>取<rt>と</rt></ruby>って"],
  ["取った", "<ruby>取<rt>と</rt></ruby>った"],
  ["取れる", "<ruby>取<rt>と</rt></ruby>れる"],
  ["戻る", "<ruby>戻<rt>もど</rt></ruby>る"],
  ["戻って", "<ruby>戻<rt>もど</rt></ruby>って"],
  ["進む", "<ruby>進<rt>すす</rt></ruby>む"],
  ["進んで", "<ruby>進<rt>すす</rt></ruby>んで"],
  ["狙う", "<ruby>狙<rt>ねら</rt></ruby>う"],
  ["飛ぶ", "<ruby>飛<rt>と</rt></ruby>ぶ"],
  ["飛んで", "<ruby>飛<rt>と</rt></ruby>んで"],
  ["飛び", "<ruby>飛<rt>と</rt></ruby>び"],
  ["落ちる", "<ruby>落<rt>お</rt></ruby>ちる"],
  ["落ちた", "<ruby>落<rt>お</rt></ruby>ちた"],
  ["向かう", "<ruby>向<rt>む</rt></ruby>かう"],
  ["向ける", "<ruby>向<rt>む</rt></ruby>ける"],
  ["呼ぶ", "<ruby>呼<rt>よ</rt></ruby>ぶ"],
  ["選ぶ", "<ruby>選<rt>えら</rt></ruby>ぶ"],
  ["選んで", "<ruby>選<rt>えら</rt></ruby>んで"],
  ["覚える", "<ruby>覚<rt>おぼ</rt></ruby>える"],
  ["触る", "<ruby>触<rt>さわ</rt></ruby>る"],
  ["触れた", "<ruby>触<rt>ふ</rt></ruby>れた"],
  ["踏む", "<ruby>踏<rt>ふ</rt></ruby>む"],
  ["返す", "<ruby>返<rt>かえ</rt></ruby>す"],
  ["追う", "<ruby>追<rt>お</rt></ruby>う"],
  ["駆け抜け", "<ruby>駆<rt>か</rt></ruby>け<ruby>抜<rt>ぬ</rt></ruby>け"],
  ["抜け", "<ruby>抜<rt>ぬ</rt></ruby>け"],
  ["持つ", "<ruby>持<rt>も</rt></ruby>つ"],
  ["起こ", "<ruby>起<rt>お</rt></ruby>こ"],
  ["良い", "<ruby>良<rt>よ</rt></ruby>い"],
  ["危ない", "<ruby>危<rt>あぶ</rt></ruby>ない"],
  ["離れ", "<ruby>離<rt>はな</rt></ruby>れ"],
  ["過ぎ", "<ruby>過<rt>す</rt></ruby>ぎ"],
  ["違う", "<ruby>違<rt>ちが</rt></ruby>う"],
  ["待つ", "<ruby>待<rt>ま</rt></ruby>つ"],
  ["終わ", "<ruby>終<rt>お</rt></ruby>わ"],
  ["座る", "<ruby>座<rt>すわ</rt></ruby>る"],
  ["受け", "<ruby>受<rt>う</rt></ruby>け"],
  ["必ず", "<ruby>必<rt>かなら</rt></ruby>ず"],
  // v840残件補完: 自動監査で残った7件の未ルビ語句を追加。
  ["当たり", "<ruby>当<rt>あ</rt></ruby>たり"],
  ["空振り後", "<ruby>空振<rt>からぶ</rt></ruby>り<ruby>後<rt>ご</rt></ruby>"],
  ["塁で", "<ruby>塁<rt>るい</rt></ruby>で"],
  ["塁は", "<ruby>塁<rt>るい</rt></ruby>は"],
  ["その後", "その<ruby>後<rt>あと</rt></ruby>"],
  ["塁の方", "<ruby>塁<rt>るい</rt></ruby>の<ruby>方<rt>ほう</rt></ruby>"],
  ["投げる人", "<ruby>投<rt>な</rt></ruby>げる<ruby>人<rt>ひと</rt></ruby>"],
  ["受ける人", "<ruby>受<rt>う</rt></ruby>ける<ruby>人<rt>ひと</rt></ruby>"],
  ["塁の近く", "<ruby>塁<rt>るい</rt></ruby>の<ruby>近<rt>ちか</rt></ruby>く"],
  ["出して", "<ruby>出<rt>だ</rt></ruby>して"],
  // v840最終補完: 監査で残った2年生以下表示語のルビを追加。
  ["ポジション名", "ポジション<ruby>名<rt>めい</rt></ruby>"],
  ["名", "<ruby>名<rt>な</rt></ruby>"],
  ["何でしょう", "<ruby>何<rt>なん</rt></ruby>でしょう"],
  ["何を", "<ruby>何<rt>なに</rt></ruby>を"],
  ["何", "<ruby>何<rt>なに</rt></ruby>"],
  ["場合でも", "<ruby>場合<rt>ばあい</rt></ruby>でも"],
  ["場合が", "<ruby>場合<rt>ばあい</rt></ruby>が"],
  ["場合", "<ruby>場合<rt>ばあい</rt></ruby>"],
  ["場で", "<ruby>場<rt>ば</rt></ruby>で"],
  ["場", "<ruby>場<rt>ば</rt></ruby>"],
  ["時点", "<ruby>時点<rt>じてん</rt></ruby>"],
  ["時です", "<ruby>時<rt>とき</rt></ruby>です"],
  ["時", "<ruby>時<rt>とき</rt></ruby>"],
  ["三つ", "<ruby>三<rt>みっ</rt></ruby>つ"],
  ["二つ", "<ruby>二<rt>ふた</rt></ruby>つ"],
  ["外へ飛んだ", "<ruby>外<rt>そと</rt></ruby>へ<ruby>飛<rt>と</rt></ruby>んだ"],
  ["外へ飛んで", "<ruby>外<rt>そと</rt></ruby>へ<ruby>飛<rt>と</rt></ruby>んで"],
  ["外へ出た", "<ruby>外<rt>そと</rt></ruby>へ<ruby>出<rt>で</rt></ruby>た"],
  ["外なら", "<ruby>外<rt>そと</rt></ruby>なら"],
  ["外を", "<ruby>外<rt>そと</rt></ruby>を"],
  ["外で", "<ruby>外<rt>そと</rt></ruby>で"],
  ["外へ", "<ruby>外<rt>そと</rt></ruby>へ"],
  ["外", "<ruby>外<rt>そと</rt></ruby>"],
  ["右", "<ruby>右<rt>みぎ</rt></ruby>"],
  ["左", "<ruby>左<rt>ひだり</rt></ruby>"],
  ["中", "<ruby>中<rt>なか</rt></ruby>"],
  ["上で", "<ruby>上<rt>うえ</rt></ruby>で"],
  ["上から", "<ruby>上<rt>うえ</rt></ruby>から"],
  ["上の", "<ruby>上<rt>うえ</rt></ruby>の"],
  ["上", "<ruby>上<rt>うえ</rt></ruby>"],
  ["体当たり", "<ruby>体当<rt>たいあ</rt></ruby>たり"],
  ["体に", "<ruby>体<rt>からだ</rt></ruby>に"],
  ["体が", "<ruby>体<rt>からだ</rt></ruby>が"],
  ["体の", "<ruby>体<rt>からだ</rt></ruby>の"],
  ["体", "<ruby>体<rt>からだ</rt></ruby>"],
  ["直し", "<ruby>直<rt>なお</rt></ruby>し"],
  ["直す", "<ruby>直<rt>なお</rt></ruby>す"],
  ["直", "<ruby>直<rt>なお</rt></ruby>"],
  ["空だけ", "<ruby>空<rt>そら</rt></ruby>だけ"],
  ["空", "<ruby>空<rt>そら</rt></ruby>"],
  ["誰にも", "<ruby>誰<rt>だれ</rt></ruby>にも"],
  ["誰", "<ruby>誰<rt>だれ</rt></ruby>"],
  ["行ける", "<ruby>行<rt>い</rt></ruby>ける"],
  ["行けるわけ", "<ruby>行<rt>い</rt></ruby>けるわけ"],
  ["行", "<ruby>行<rt>い</rt></ruby>"],
  ["取れない", "<ruby>取<rt>と</rt></ruby>れない"],
  ["取れば", "<ruby>取<rt>と</rt></ruby>れば"],
  ["取", "<ruby>取<rt>と</rt></ruby>"],
  ["走れる", "<ruby>走<rt>はし</rt></ruby>れる"],
  ["走れません", "<ruby>走<rt>はし</rt></ruby>れません"],
  ["走れば", "<ruby>走<rt>はし</rt></ruby>れば"],
  ["走らず", "<ruby>走<rt>はし</rt></ruby>らず"],
  ["走", "<ruby>走<rt>はし</rt></ruby>"],
  ["通っていない", "<ruby>通<rt>とお</rt></ruby>っていない"],
  ["通り", "<ruby>通<rt>とお</rt></ruby>り"],
  ["通", "<ruby>通<rt>とお</rt></ruby>"],
  ["言える", "<ruby>言<rt>い</rt></ruby>える"],
  ["言いにくい", "<ruby>言<rt>い</rt></ruby>いにくい"],
  ["言", "<ruby>言<rt>い</rt></ruby>"],
  ["曲げた", "<ruby>曲<rt>ま</rt></ruby>げた"],
  ["曲", "<ruby>曲<rt>ま</rt></ruby>"],
  ["勢い", "<ruby>勢<rt>いきお</rt></ruby>い"],
  ["気を", "<ruby>気<rt>き</rt></ruby>を"],
  ["気", "<ruby>気<rt>き</rt></ruby>"],
  ["胸の", "<ruby>胸<rt>むね</rt></ruby>の"],
  ["入れすぎず", "<ruby>入<rt>い</rt></ruby>れすぎず"],
  ["入れ", "<ruby>入<rt>い</rt></ruby>れ"],
  ["合わ", "<ruby>合<rt>あ</rt></ruby>わ"],
  ["合", "<ruby>合<rt>あ</rt></ruby>"],
  // v840追加監査: 2年生以下の問題文・選択肢で未ルビだった語句を補完。
  ["弱い", "<ruby>弱<rt>よわ</rt></ruby>い"],
  ["前への", "<ruby>前<rt>まえ</rt></ruby>への"],
  ["間に合いにくい", "<ruby>間<rt>ま</rt></ruby>に<ruby>合<rt>あ</rt></ruby>いにくい"],
  ["間に合わない", "<ruby>間<rt>ま</rt></ruby>に<ruby>合<rt>あ</rt></ruby>わない"],
  ["間に合", "<ruby>間<rt>ま</rt></ruby>に<ruby>合<rt>あ</rt></ruby>"],
  ["合う", "<ruby>合<rt>あ</rt></ruby>う"],
  ["見る", "<ruby>見<rt>み</rt></ruby>る"],
  ["見て", "<ruby>見<rt>み</rt></ruby>て"],
  ["見ず", "<ruby>見<rt>み</rt></ruby>ず"],
  ["見ない", "<ruby>見<rt>み</rt></ruby>ない"],
  ["見える", "<ruby>見<rt>み</rt></ruby>える"],
  ["声", "<ruby>声<rt>こえ</rt></ruby>"],
  ["来ていない", "<ruby>来<rt>き</rt></ruby>ていない"],
  ["来る", "<ruby>来<rt>く</rt></ruby>る"],
  ["来ました", "<ruby>来<rt>き</rt></ruby>ました"],
  ["走路", "<ruby>走路<rt>そうろ</rt></ruby>"],
  ["立っています", "<ruby>立<rt>た</rt></ruby>っています"],
  ["立つ", "<ruby>立<rt>た</rt></ruby>つ"],
  ["立った", "<ruby>立<rt>た</rt></ruby>った"],
  ["立って", "<ruby>立<rt>た</rt></ruby>って"],
  ["捕球後", "<ruby>捕球後<rt>ほきゅうご</rt></ruby>"],
  ["直後", "<ruby>直後<rt>ちょくご</rt></ruby>"],
  ["後、", "<ruby>後<rt>あと</rt></ruby>、"],
  ["役割", "<ruby>役割<rt>やくわり</rt></ruby>"],
  ["二塁手", "<ruby>二塁手<rt>にるいしゅ</rt></ruby>"],
  ["球", "<ruby>球<rt>たま</rt></ruby>"],
  ["無理", "<ruby>無理<rt>むり</rt></ruby>"],
  ["手", "<ruby>手<rt>て</rt></ruby>"],
  ["先", "<ruby>先<rt>さき</rt></ruby>"],
  ["投げます", "<ruby>投<rt>な</rt></ruby>げます"],
  ["歩く", "<ruby>歩<rt>ある</rt></ruby>く"],
  ["当たりません", "<ruby>当<rt>あ</rt></ruby>たりません"],
  ["当たらなければ", "<ruby>当<rt>あ</rt></ruby>たらなければ"],
  ["当たった", "<ruby>当<rt>あ</rt></ruby>たった"],
  ["当たりました", "<ruby>当<rt>あ</rt></ruby>たりました"],
  ["行きません", "<ruby>行<rt>い</rt></ruby>きません"],
  ["行く", "<ruby>行<rt>い</rt></ruby>く"],
  ["走ります", "<ruby>走<rt>はし</rt></ruby>ります"],
  ["走りました", "<ruby>走<rt>はし</rt></ruby>りました"],
  ["走っています", "<ruby>走<rt>はし</rt></ruby>っています"],
  ["走り", "<ruby>走<rt>はし</rt></ruby>り"],
  ["触りました", "<ruby>触<rt>さわ</rt></ruby>りました"],
  ["触れる", "<ruby>触<rt>ふ</rt></ruby>れる"],
  ["言います", "<ruby>言<rt>い</rt></ruby>います"],
  ["言う", "<ruby>言<rt>い</rt></ruby>う"],
  ["同じ", "<ruby>同<rt>おな</rt></ruby>じ"],
  ["人が", "<ruby>人<rt>ひと</rt></ruby>が"],
  ["人は", "<ruby>人<rt>ひと</rt></ruby>は"],
  ["人の", "<ruby>人<rt>ひと</rt></ruby>の"],
  ["人に", "<ruby>人<rt>ひと</rt></ruby>に"],
  ["人で", "<ruby>人<rt>ひと</rt></ruby>で"],
  ["人", "<ruby>人<rt>ひと</rt></ruby>"],
  ["走り方", "<ruby>走<rt>はし</rt></ruby>り<ruby>方<rt>かた</rt></ruby>"],
  ["方", "<ruby>方<rt>かた</rt></ruby>"],
  ["目を", "<ruby>目<rt>め</rt></ruby>を"],
  ["目の", "<ruby>目<rt>め</rt></ruby>の"],
  ["目", "<ruby>目<rt>め</rt></ruby>"],
  ["力", "<ruby>力<rt>ちから</rt></ruby>"],
  ["膝", "<ruby>膝<rt>ひざ</rt></ruby>"],
  ["頭", "<ruby>頭<rt>あたま</rt></ruby>"],
  ["顔", "<ruby>顔<rt>かお</rt></ruby>"],
  ["胸", "<ruby>胸<rt>むね</rt></ruby>"],
  ["足", "<ruby>足<rt>あし</rt></ruby>"],
  ["構え", "<ruby>構<rt>かま</rt></ruby>え"],
  ["構える", "<ruby>構<rt>かま</rt></ruby>える"],
  ["反応", "<ruby>反応<rt>はんのう</rt></ruby>"],
  ["止まり", "<ruby>止<rt>と</rt></ruby>まり"],
  ["止まら", "<ruby>止<rt>と</rt></ruby>まら"],
  ["止め", "<ruby>止<rt>と</rt></ruby>め"],
  ["止まる", "<ruby>止<rt>と</rt></ruby>まる"],
  ["固まって", "<ruby>固<rt>かた</rt></ruby>まって"],
  ["固まり", "<ruby>固<rt>かた</rt></ruby>まり"],
  ["上がって", "<ruby>上<rt>あ</rt></ruby>がって"],
  ["上がりました", "<ruby>上<rt>あ</rt></ruby>がりました"],
  ["上を", "<ruby>上<rt>うえ</rt></ruby>を"],
  ["下", "<ruby>下<rt>した</rt></ruby>"],
  ["左右", "<ruby>左右<rt>さゆう</rt></ruby>"],
  ["回れる", "<ruby>回<rt>まわ</rt></ruby>れる"],
  ["回り", "<ruby>回<rt>まわ</rt></ruby>り"],
  ["向かって", "<ruby>向<rt>む</rt></ruby>かって"],
  ["向かいました", "<ruby>向<rt>む</rt></ruby>かいました"],
  ["間で", "<ruby>間<rt>あいだ</rt></ruby>で"],
  ["間に", "<ruby>間<rt>あいだ</rt></ruby>に"],
  ["間を", "<ruby>間<rt>あいだ</rt></ruby>を"],
  ["間", "<ruby>間<rt>あいだ</rt></ruby>"],
  // v840: 2年生以下ルビ精査。1文字汎用ルビの誤読を避けるため、低学年問題で使う語句単位の読みを追加。
  ["三塁側", "<ruby>三塁側<rt>さんるいがわ</rt></ruby>"],
  ["攻撃側", "<ruby>攻撃側<rt>こうげきがわ</rt></ruby>"],
  ["意識", "<ruby>意識<rt>いしき</rt></ruby>"],
  ["注意", "<ruby>注意<rt>ちゅうい</rt></ruby>"],
  ["意思", "<ruby>意思<rt>いし</rt></ruby>"],
  ["遅れる", "<ruby>遅<rt>おく</rt></ruby>れる"],
  ["遅れます", "<ruby>遅<rt>おく</rt></ruby>れます"],
  ["遅く", "<ruby>遅<rt>おそ</rt></ruby>く"],
  ["基本的", "<ruby>基本的<rt>きほんてき</rt></ruby>"],
  ["基本", "<ruby>基本<rt>きほん</rt></ruby>"],
  ["あり得る", "あり<ruby>得<rt>え</rt></ruby>る"],
  ["得る", "<ruby>得<rt>え</rt></ruby>る"],
  ["有無", "<ruby>有無<rt>うむ</rt></ruby>"],
  ["要求する", "<ruby>要求<rt>ようきゅう</rt></ruby>する"],
  ["要求しない", "<ruby>要求<rt>ようきゅう</rt></ruby>しない"],
  ["要求", "<ruby>要求<rt>ようきゅう</rt></ruby>"],
  ["準備", "<ruby>準備<rt>じゅんび</rt></ruby>"],
  ["備える", "<ruby>備<rt>そな</rt></ruby>える"],
  ["備えます", "<ruby>備<rt>そな</rt></ruby>えます"],
  ["備え", "<ruby>備<rt>そな</rt></ruby>え"],
  ["姿勢", "<ruby>姿勢<rt>しせい</rt></ruby>"],
  ["接触", "<ruby>接触<rt>せっしょく</rt></ruby>"],
  ["取りつつ", "<ruby>取<rt>と</rt></ruby>りつつ"],
  ["取りました", "<ruby>取<rt>と</rt></ruby>りました"],
  ["取りそこね", "<ruby>取<rt>と</rt></ruby>りそこね"],
  ["取られず", "<ruby>取<rt>と</rt></ruby>られず"],
  ["取られました", "<ruby>取<rt>と</rt></ruby>られました"],
  ["取られ", "<ruby>取<rt>と</rt></ruby>られ"],
  ["次の", "<ruby>次<rt>つぎ</rt></ruby>の"],
  ["次に", "<ruby>次<rt>つぎ</rt></ruby>に"],
  ["返球", "<ruby>返球<rt>へんきゅう</rt></ruby>"],
  ["完全", "<ruby>完全<rt>かんぜん</rt></ruby>"],
  ["全部", "<ruby>全部<rt>ぜんぶ</rt></ruby>"],
  ["飛んだ", "<ruby>飛<rt>と</rt></ruby>んだ"],
  ["飛びました", "<ruby>飛<rt>と</rt></ruby>びました"],
  ["覚えましょう", "<ruby>覚<rt>おぼ</rt></ruby>えましょう"],
  ["攻撃", "<ruby>攻撃<rt>こうげき</rt></ruby>"],
  ["打撃", "<ruby>打撃<rt>だげき</rt></ruby>"],
  ["一塁線", "<ruby>一塁線<rt>いちるいせん</rt></ruby>"],
  ["三塁線", "<ruby>三塁線<rt>さんるいせん</rt></ruby>"],
  ["塁から", "<ruby>塁<rt>るい</rt></ruby>から"],
  ["塁へ", "<ruby>塁<rt>るい</rt></ruby>へ"],
  ["塁を", "<ruby>塁<rt>るい</rt></ruby>を"],
  ["塁に", "<ruby>塁<rt>るい</rt></ruby>に"],
  ["踏んだ", "<ruby>踏<rt>ふ</rt></ruby>んだ"],
  ["踏みます", "<ruby>踏<rt>ふ</rt></ruby>みます"],
  ["危険", "<ruby>危険<rt>きけん</rt></ruby>"],
  ["座って", "<ruby>座<rt>すわ</rt></ruby>って"],
  ["勝手", "<ruby>勝手<rt>かって</rt></ruby>"],
  ["離さない", "<ruby>離<rt>はな</rt></ruby>さない"],
  ["背中", "<ruby>背中<rt>せなか</rt></ruby>"],
  ["背筋", "<ruby>背筋<rt>せすじ</rt></ruby>"],
  ["増えます", "<ruby>増<rt>ふ</rt></ruby>えます"],
  ["一度", "<ruby>一度<rt>いちど</rt></ruby>"],
  ["打席", "<ruby>打席<rt>だせき</rt></ruby>"],
  ["二塁打", "<ruby>二塁打<rt>にるいだ</rt></ruby>"],
  ["振って", "<ruby>振<rt>ふ</rt></ruby>って"],
  ["振りました", "<ruby>振<rt>ふ</rt></ruby>りました"],
  ["振らなければ", "<ruby>振<rt>ふ</rt></ruby>らなければ"],
  ["振りません", "<ruby>振<rt>ふ</rt></ruby>りません"],
  ["振らなかった", "<ruby>振<rt>ふ</rt></ruby>らなかった"],
  ["向こう", "<ruby>向<rt>む</rt></ruby>こう"],
  ["向きました", "<ruby>向<rt>む</rt></ruby>きました"],
  ["転がれば", "<ruby>転<rt>ころ</rt></ruby>がれば"],
  ["最初", "<ruby>最初<rt>さいしょ</rt></ruby>"],
  ["最善", "<ruby>最善<rt>さいぜん</rt></ruby>"],
  ["最も", "<ruby>最<rt>もっと</rt></ruby>も"],
  ["最後", "<ruby>最後<rt>さいご</rt></ruby>"],
  ["戻りません", "<ruby>戻<rt>もど</rt></ruby>りません"],
  ["戻ります", "<ruby>戻<rt>もど</rt></ruby>ります"],
  ["順番", "<ruby>順番<rt>じゅんばん</rt></ruby>"],
  ["攻守交代", "<ruby>攻守交代<rt>こうしゅこうたい</rt></ruby>"],
  ["着けば", "<ruby>着<rt>つ</rt></ruby>けば"],
  ["着き", "<ruby>着<rt>つ</rt></ruby>き"],
  ["持った", "<ruby>持<rt>も</rt></ruby>った"],
  ["持って", "<ruby>持<rt>も</rt></ruby>って"],
  ["中継", "<ruby>中継<rt>ちゅうけい</rt></ruby>"],
  ["落ちました", "<ruby>落<rt>お</rt></ruby>ちました"],
  ["結果", "<ruby>結果<rt>けっか</rt></ruby>"],
  ["違います", "<ruby>違<rt>ちが</rt></ruby>います"],
  ["目指せる", "<ruby>目指<rt>めざ</rt></ruby>せる"],
  ["可能性", "<ruby>可能性<rt>かのうせい</rt></ruby>"],
  ["真ん中", "<ruby>真<rt>ま</rt></ruby>ん<ruby>中<rt>なか</rt></ruby>"],
  ["審判", "<ruby>審判<rt>しんぱん</rt></ruby>"],
  ["低学年", "<ruby>低学年<rt>ていがくねん</rt></ruby>"],
  ["目安", "<ruby>目安<rt>めやす</rt></ruby>"],
  ["決める", "<ruby>決<rt>き</rt></ruby>める"],
  ["限りません", "<ruby>限<rt>かぎ</rt></ruby>りません"],
  ["続く", "<ruby>続<rt>つづ</rt></ruby>く"],
  ["待ち方", "<ruby>待<rt>ま</rt></ruby>ち<ruby>方<rt>かた</rt></ruby>"],
  ["動ける", "<ruby>動<rt>うご</rt></ruby>ける"],
  ["打球処理", "<ruby>打球処理<rt>だきゅうしょり</rt></ruby>"],
  ["処理", "<ruby>処理<rt>しょり</rt></ruby>"],
  ["空ける", "<ruby>空<rt>あ</rt></ruby>ける"],
  ["空く", "<ruby>空<rt>あ</rt></ruby>く"],
  ["空けたり", "<ruby>空<rt>あ</rt></ruby>けたり"],
  ["付近", "<ruby>付近<rt>ふきん</rt></ruby>"],
  ["助ける", "<ruby>助<rt>たす</rt></ruby>ける"],
  ["助け", "<ruby>助<rt>たす</rt></ruby>け"],
  ["時は", "<ruby>時<rt>とき</rt></ruby>は"],
  ["時の", "<ruby>時<rt>とき</rt></ruby>の"],
  ["時に", "<ruby>時<rt>とき</rt></ruby>に"],
  ["入って", "<ruby>入<rt>はい</rt></ruby>って"],
  ["入った", "<ruby>入<rt>はい</rt></ruby>った"],
  ["入らない", "<ruby>入<rt>はい</rt></ruby>らない"],
  ["入る", "<ruby>入<rt>はい</rt></ruby>る"],
  ["入り", "<ruby>入<rt>はい</rt></ruby>り"],
  ["作る", "<ruby>作<rt>つく</rt></ruby>る"],
  ["空けること", "<ruby>空<rt>あ</rt></ruby>けること"],
  ["考える", "<ruby>考<rt>かんが</rt></ruby>える"],
  ["受ける", "<ruby>受<rt>う</rt></ruby>ける"],
  ["受けた", "<ruby>受<rt>う</rt></ruby>けた"],
  ["受けて", "<ruby>受<rt>う</rt></ruby>けて"],
  ["優先", "<ruby>優先<rt>ゆうせん</rt></ruby>"],
  ["様子", "<ruby>様子<rt>ようす</rt></ruby>"],
  ["状況", "<ruby>状況<rt>じょうきょう</rt></ruby>"],
  ["中心", "<ruby>中心<rt>ちゅうしん</rt></ruby>"],
  ["周辺", "<ruby>周辺<rt>しゅうへん</rt></ruby>"],
  ["両方", "<ruby>両方<rt>りょうほう</rt></ruby>"],
  ["正確", "<ruby>正確<rt>せいかく</rt></ruby>"],
  ["確実", "<ruby>確実<rt>かくじつ</rt></ruby>"],
  ["迷わず", "<ruby>迷<rt>まよ</rt></ruby>わず"],
  ["安全策", "<ruby>安全策<rt>あんぜんさく</rt></ruby>"],
  ["合いにくい", "<ruby>合<rt>あ</rt></ruby>いにくい"],
  ["合わない", "<ruby>合<rt>あ</rt></ruby>わない"],
  ["動き出し", "<ruby>動<rt>うご</rt></ruby>き<ruby>出<rt>だ</rt></ruby>し"],
  ["出す", "<ruby>出<rt>だ</rt></ruby>す"],
  ["出た", "<ruby>出<rt>で</rt></ruby>た"],
  ["出たり", "<ruby>出<rt>で</rt></ruby>たり"],
  ["出ました", "<ruby>出<rt>で</rt></ruby>ました"],
  ["下がる", "<ruby>下<rt>さ</rt></ruby>がる"],
  ["大きな", "<ruby>大<rt>おお</rt></ruby>きな"],
  ["大きく", "<ruby>大<rt>おお</rt></ruby>きく"],
  ["多い", "<ruby>多<rt>おお</rt></ruby>い"],
  ["少し", "<ruby>少<rt>すこ</rt></ruby>し"],
  ["近く", "<ruby>近<rt>ちか</rt></ruby>く"],
  ["近い", "<ruby>近<rt>ちか</rt></ruby>い"],
  ["上がり", "<ruby>上<rt>あ</rt></ruby>がり"],
  ["上がる", "<ruby>上<rt>あ</rt></ruby>がる"],
  ["上がった", "<ruby>上<rt>あ</rt></ruby>がった"],
  ["高く", "<ruby>高<rt>たか</rt></ruby>く"],
  ["高い", "<ruby>高<rt>たか</rt></ruby>い"],
  ["高さ", "<ruby>高<rt>たか</rt></ruby>さ"],
  ["低すぎ", "<ruby>低<rt>ひく</rt></ruby>すぎ"],
  ["高すぎ", "<ruby>高<rt>たか</rt></ruby>すぎ"],
  ["通り過ぎ", "<ruby>通<rt>とお</rt></ruby>り<ruby>過<rt>す</rt></ruby>ぎ"],
  ["通りました", "<ruby>通<rt>とお</rt></ruby>りました"],
  ["通った", "<ruby>通<rt>とお</rt></ruby>った"],
  ["通る", "<ruby>通<rt>とお</rt></ruby>る"],
  ["曲げて", "<ruby>曲<rt>ま</rt></ruby>げて"],
  ["伸ばして", "<ruby>伸<rt>の</rt></ruby>ばして"],
  ["軽く", "<ruby>軽<rt>かる</rt></ruby>く"],
  ["野手", "<ruby>野手<rt>やしゅ</rt></ruby>"],
  ["人数", "<ruby>人数<rt>にんずう</rt></ruby>"],
  ["何人", "<ruby>何人<rt>なんにん</rt></ruby>"],
  ["分かり", "<ruby>分<rt>わ</rt></ruby>かり"],
  ["分かる", "<ruby>分<rt>わ</rt></ruby>かる"],
  ["大切", "<ruby>大切<rt>たいせつ</rt></ruby>"],
  ["先に", "<ruby>先<rt>さき</rt></ruby>に"],
  ["先なら", "<ruby>先<rt>さき</rt></ruby>なら"],
  ["後に", "<ruby>後<rt>あと</rt></ruby>に"],
  ["後の", "<ruby>後<rt>あと</rt></ruby>の"],
  ["前に", "<ruby>前<rt>まえ</rt></ruby>に"],
  ["前の", "<ruby>前<rt>まえ</rt></ruby>の"],
  ["前へ", "<ruby>前<rt>まえ</rt></ruby>へ"],
  ["後ろ", "<ruby>後<rt>うし</rt></ruby>ろ"],
  ["前後", "<ruby>前後<rt>ぜんご</rt></ruby>"],
  ["線の", "<ruby>線<rt>せん</rt></ruby>の"],
  ["線外", "<ruby>線外<rt>せんがい</rt></ruby>"],
  ["線内", "<ruby>線内<rt>せんない</rt></ruby>"],
  ["走塁妨害", "<ruby>走塁妨害<rt>そうるいぼうがい</rt></ruby>"],
  ["遠く", "<ruby>遠<rt>とお</rt></ruby>く"],
  ["遠くへ", "<ruby>遠<rt>とお</rt></ruby>くへ"],
];
const RUBY_MAP=Object.fromEntries(RUBY_ENTRIES);
const RUBY_PATTERN=new RegExp(
  RUBY_ENTRIES
    .map(([word])=>word)
    .sort((a,b)=>b.length-a.length)
    .map(word=>escapeHtml(word).replace(/[.*+?^${}()|[\]\\]/g,"\\$&"))
    .join("|"),
  "g"
);
function rubyHtml(text){
  const html=escapeHtml(text);
  if(!shouldUseRuby())return html;
  return html.replace(RUBY_PATTERN,match=>RUBY_MAP[match]||match);
}
function setRubyText(el,text){
  if(!el)return;
  if(shouldUseRuby())el.innerHTML=rubyHtml(text);
  else el.textContent=String(text??"");
}

function displaySituation(q){
  let s = q.situation;
  if(isTwoOutOrdinaryInfieldGrounder(q) || isTwoOutPitcherFrontGrounderOrBunt(q)){
    // 2アウト時の送球先は選択肢で判断させる。状況文では答えが分かる表現を出さない。
    s = s.replace(/三塁走者が本塁へ向かい、本塁送球の場面。?/g, "三塁走者が本塁へ向かっている。送球先の判断が必要。");
    s = s.replace(/本塁送球の場面。?/g, "送球先の判断が必要。");
    s = s.replace(/本塁送球は難しい。?/g, "次の送球判断が必要。");
  }
  if(q.type==="defense" && STATE.position==="P"){
    s = s.replaceAll("ピッチャーが", "あなたが");
    s = s.replaceAll("ピッチャーは", "あなたは");
    s = s.replaceAll("ピッチャーを", "あなたを");
    s = s.replaceAll("ピッチャーの", "あなたの");
    s = s.replaceAll("ボールはピッチャーが持っている", "ボールはあなたが持っている");
    s = s.replaceAll("ピッチャーがプレイしている", "あなたがプレイしている");
  }
  if(q.type==="defense" && STATE.position==="C"){
    s = s.replaceAll("キャッチャーの前", "あなたの前");
    s = s.replaceAll("キャッチャー前", "あなたの前");
    s = s.replaceAll("キャッチャー手前", "あなたの手前");
    s = s.replaceAll("キャッチャーが", "あなたが");
    s = s.replaceAll("キャッチャーは", "あなたは");
    s = s.replaceAll("キャッチャーを", "あなたを");
    s = s.replaceAll("キャッチャーの", "あなたの");
    s = s.replaceAll("キャッチャーへ", "あなたへ");
    s = s.replaceAll("キャッチャーに", "あなたに");
    s = s.replaceAll("キャッチャー", "あなた");
    s = s.replaceAll("捕手が", "あなたが");
    s = s.replaceAll("捕手は", "あなたは");
    s = s.replaceAll("捕手を", "あなたを");
    s = s.replaceAll("捕手の", "あなたの");
    s = s.replaceAll("捕手へ", "あなたへ");
    s = s.replaceAll("捕手に", "あなたに");
    s = s.replaceAll("捕手", "あなた");
  }
  if(q.type==="defense" && q.visual && q.visual.ball_holder === STATE.position){
    const jp = labelForPos(STATE.position);
    const posRe = new RegExp(jp+"が[^。]*?しっかり捕球し", "g");
    s = s.replace(posRe, "あなたがしっかり捕球し");
    const posReDone = new RegExp(jp+"が[^。]*?しっかり捕球した", "g");
    s = s.replace(posReDone, "あなたがしっかり捕球した");
    s = s.replaceAll(jp+"が捕球し", "あなたが捕球し");
    s = s.replaceAll(jp+"が捕球した", "あなたが捕球した");
    s = s.replaceAll(jp+"が前進して捕球した", "あなたが前進して捕球した");
    s = s.replaceAll(jp+"が前進し", "あなたが前進し");
    s = s.replaceAll(jp+"が追い", "あなたが追い");
    const samePosTerms = {
      "P": [["ピッチャーゴロ","あなたの守備位置へのゴロ"],["ピッチャー前","あなたの前"],["投手前","あなたの前"],["投手ゴロ","あなたの守備位置へのゴロ"],["ピッチャーが","あなたが"],["ピッチャーは","あなたは"],["ピッチャーの","あなたの"],["投手が","あなたが"],["投手は","あなたは"],["投手の","あなたの"]],
      "1B": [["ファーストゴロ","あなたの守備位置へのゴロ"],["ファースト前","あなたの前"],["ファーストが","あなたが"],["ファーストは","あなたは"],["ファーストの","あなたの"],["ファーストへの","あなたへの"]],
      "2B": [["セカンドゴロ","あなたの守備位置へのゴロ"],["セカンド前","あなたの前"],["セカンドが","あなたが"],["セカンドは","あなたは"],["セカンドの","あなたの"],["セカンドへの","あなたへの"]],
      "SS": [["ショートゴロ","あなたの守備位置へのゴロ"],["ショート前","あなたの前"],["ショートが","あなたが"],["ショートは","あなたは"],["ショートの","あなたの"],["ショートへの","あなたへの"]],
      "3B": [["サードゴロ","あなたの守備位置へのゴロ"],["サード前","あなたの前"],["サードが","あなたが"],["サードは","あなたは"],["サードの","あなたの"],["サードへの","あなたへの"]],
      "LF": [["レフト前","あなたの前"],["レフトフライ","あなたの守備位置へのフライ"],["レフトが","あなたが"],["レフトは","あなたは"],["レフトの","あなたの"],["レフトへの","あなたへの"]],
      "CF": [["センター前","あなたの前"],["センターフライ","あなたの守備位置へのフライ"],["センターが","あなたが"],["センターは","あなたは"],["センターの","あなたの"],["センターへの","あなたへの"]],
      "RF": [["ライト前","あなたの前"],["ライトフライ","あなたの守備位置へのフライ"],["ライトが","あなたが"],["ライトは","あなたは"],["ライトの","あなたの"],["ライトへの","あなたへの"]]
    };
    const reps = samePosTerms[STATE.position] || [];
    reps.forEach(([a,b])=>{s=s.replaceAll(a,b)});
    const bareSamePosTerms = {
      "P": [["ピッチャー","あなた"],["投手","あなた"]],
      "1B": [["ファースト","あなた"]],
      "2B": [["セカンド","あなた"]],
      "SS": [["ショート","あなた"]],
      "3B": [["サード","あなた"]],
      "LF": [["レフト","あなた"]],
      "CF": [["センター","あなた"]],
      "RF": [["ライト","あなた"]]
    };
    (bareSamePosTerms[STATE.position]||[]).forEach(([a,b])=>{s=s.replaceAll(a,b)});
  }
  return normalizeRunnerLeadText(s);
}

function displayTitle(q){
  // v447: タイトルには「あなた」補正をかけない。
  // 例: 「レフト線ヒット」「一塁のレフト前ヒット」は、守備位置がレフトでもタイトルをそのまま表示する。
  // 説明文 displaySituation(q) 側の「あなた」補正は維持する。
  let title = q.ball_tag || "";
  if(q.type==="defense" && STATE.position==="P"){
    title = title.replace("一塁への牽制カバー", "一塁への牽制判断");
    title = title.replace("二塁への牽制カバー", "二塁への牽制判断");
    title = title.replace("三塁への牽制カバー", "三塁への牽制判断");
    title = title.replace("牽制・偽投カバー", "牽制判断");
    title = title.replace("偽投カバー", "牽制判断");
  }
  if(q.type==="defense" && STATE.position==="C"){
    title = title.replace("キャッチャーフライ", "本塁付近のフライ");
  }
  return title;
}


function runnerLabelFromArray(runners){
  const arr=Array.isArray(runners)?runners.filter(Boolean).map(String):[];
  const uniq=[...new Set(arr)].sort();
  const key=uniq.join(",");
  const map={
    "":"なし",
    "1B":"一塁",
    "2B":"二塁",
    "3B":"三塁",
    "1B,2B":"一・二塁",
    "1B,3B":"一・三塁",
    "2B,3B":"二・三塁",
    "1B,2B,3B":"満塁"
  };
  return map[key]||"なし";
}
function statusRunnerLabel(q){
  const st=String((q&&q.stage)||"");
  if(q&&q.type==="basic")return "基本";
  // v423: 動的出題で q.stage が差し替わっても、画面上の実走者は visual.runners を優先する。
  const visualRunners=q&&q.visual&&Array.isArray(q.visual.runners)?q.visual.runners:null;
  if(visualRunners && visualRunners.length)return runnerLabelFromArray(visualRunners);
  const map={
    "none":"なし",
    "BR":"打者走者",
    "1B":"一塁",
    "2B":"二塁",
    "3B":"三塁",
    "1B2B":"一・二塁",
    "1B3B":"一・三塁",
    "2B3B":"二・三塁",
    "1B2B3B":"満塁",
    "BASIC":"基本"
  };
  return map[st]||stageLabel(st).replace("走者：","").replace("走者なし","なし").replace("ランナー","")||"なし";
}
function normalizeRunnerLeadText(s){
  s=String(s||"");
  const reps=[
    [/^(?:ランナー|走者)?[：:・\s]*一塁[・･]?二塁[。．]/, "ランナーは一塁・二塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*一塁[・･]?三塁[。．]/, "ランナーは一塁・三塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*二塁[・･]?三塁[。．]/, "ランナーは二塁・三塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*一[・･]二塁[。．]/, "ランナーは一塁・二塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*一[・･]三塁[。．]/, "ランナーは一塁・三塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*二[・･]三塁[。．]/, "ランナーは二塁・三塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*満塁[。．]/, "ランナーは満塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*一塁[。．]/, "ランナーは一塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*二塁[。．]/, "ランナーは二塁。"],
    [/^(?:ランナー|走者)?[：:・\s]*三塁[。．]/, "ランナーは三塁。"]
  ];
  reps.forEach(([a,b])=>{s=s.replace(a,b)});
  return s;
}
function mistakeRunnerContextLabel(q){
  if(!q||q.type==="basic")return q&&q.type==="basic"?"基本動作":"";
  const r=statusRunnerLabel(q);
  if(!r||r==="基本")return "";
  return `ランナー：${r}`;
}
function mistakeOwnRunnerLabel(q){
  if(!q||q.type!=="attack")return "";
  const loc=ownAttackLocationLabel(q);
  if(!loc||loc==="自分"||loc==="打者"||loc==="基本")return "";
  if(loc==="打者走者")return "あなたは打者走者です";
  return `あなたは${loc}ランナーです`;
}
function ownAttackLocationLabel(q){
  if(!q)return "";
  const text=normalizeSimilarText(`${q.ball_tag||""} ${q.situation||""} ${q.prompt||""}`);
  const st=String(q.stage||"");
  // v429: 「自分の場所」は、文中に出る他の走者ではなく「あなたは○○」を最優先する。
  if(/あなたは打者走者|あなたはバッターランナー|自分が打者走者|自分がバッターランナー/.test(text))return "打者走者";
  if(/あなたは三塁ランナー|自分が三塁ランナー|あなたが三塁ランナー|自分は三塁ランナー/.test(text))return "三塁";
  if(/あなたは二塁ランナー|自分が二塁ランナー|あなたが二塁ランナー|自分は二塁ランナー/.test(text))return "二塁";
  if(/あなたは一塁ランナー|自分が一塁ランナー|あなたが一塁ランナー|自分は一塁ランナー/.test(text))return "一塁";
  // 走路・出発地点を問う問題は「○塁から」を補助判定に使う
  if(/三塁から/.test(text))return "三塁";
  if(/二塁から/.test(text))return "二塁";
  if(/一塁から/.test(text))return "一塁";
  // 明示的な自分表現がない場合だけ、単独走者stageを使う。複数走者stageは自分判定に使わない。
  const map={"none":"打者","BR":"打者走者","1B":"一塁","2B":"二塁","3B":"三塁","BASIC":"基本"};
  if(map[st])return map[st];
  if(/打者走者|バッターランナー/.test(text))return "打者走者";
  return "自分";
}
function roleLocationLabel(q){
  if(!q)return "";
  if(q.type==="basic")return "基本";
  if(q.type==="attack")return ownAttackLocationLabel(q);
  return labelForPos(STATE.position||"");
}
function updateGameStatusPanel(q){
  const shell=$("gameShell");
  const badge=$("roleBadge");
  const roleLabel=$("roleCardLabel");
  const topBadge=$("topRoleBadge");
  const runner=$("statusRunner");
  if(!q||!shell||!badge||!runner)return;
  shell.classList.remove("mode-attack","mode-defense","mode-basic");
  const mode=q.type==="basic"?"basic":q.type==="attack"?"attack":"defense";
  shell.classList.add(`mode-${mode}`);
  const roleText=q.type==="basic"?"基本":q.type==="attack"?"攻撃":"守備";
  const roleHeading=q.type==="basic"?"モード":q.type==="attack"?"あなたは":"ポジション";
  badge.textContent=roleLocationLabel(q);
  if(roleLabel)roleLabel.textContent=roleHeading;
  if(topBadge)topBadge.textContent=roleText;
  runner.textContent=statusRunnerLabel(q);
}

function renderQuestion(){const q=STATE.sequence[STATE.current];if(!q){finishGame();return}updateGameStatusPanel(q);$("inningLabel").textContent=q.inning;$("outDots").innerHTML=[0,1,2].map(i=>`<span class="outdot ${i<q.outs?"on":""}"></span>`).join("");$("scoreMini").innerHTML=`<span class="score-now">${STATE.score}</span><span class="score-unit">点</span><span class="score-max">/ ${maxScoreForCurrentGame()}点</span>`;$("qType").textContent=q.type==="basic"?"基本動作":(q.type==="attack"?"攻撃":`守備：${STATE.config.positions[STATE.position]}`);$("qId").textContent=q.id||"";$("qId").style.display=q.id?"inline-flex":"none";$("qTheme").textContent="";$("qTheme").style.display="none";$("qStage").textContent=q.type==="basic"?stageLabel(q.stage):`ランナー：${statusRunnerLabel(q)}`;const holder=((q.visual||{}).ball_holder)||playTarget((q.visual||{}).ball_path);const qHolderText=q.type==="defense"?`ボール：${holder===STATE.position?"自分":labelForPos(holder)}`:"";$("qHolder").textContent=qHolderText;$("qHolder").style.display=qHolderText?"inline-flex":"none";setRubyText($("qTitle"), displayTitle(q));setRubyText($("qSituation"), q.type==="basic"?`${q.inning}。${displaySituation(q)}`:displaySituation(q));setRubyText($("qPrompt"), promptText(q));$("fieldCanvas").innerHTML=makeFieldSvg(q);startQuestionTimer(q);const choices=shuffle(getChoices(q).map((c,i)=>({...c,original:i})));$("choices").innerHTML="";if(!choices.length){const msg=document.createElement("p");msg.className="choice-empty";msg.textContent="この問題の選択肢が見つかりません。";$("choices").appendChild(msg);return}choices.forEach((c,idx)=>{const btn=document.createElement("button");btn.className="choice";const letter=document.createElement("span");letter.className="letter";letter.textContent=String.fromCharCode(65+idx);btn.appendChild(letter);const txt=document.createElement("span");txt.className="choice-text";txt.innerHTML=rubyHtml(c.text||"");btn.append(txt);btn.addEventListener("click",()=>answer(q,c));$("choices").appendChild(btn)})}
function scoreForSelectedChoice(q,c){
  const text=String((c&&c.text)||"");
  if(isAttackInfieldLiner(q) && text.includes("すぐベースに戻る"))return 3;
  const n=Number(c&&c.score);
  return Number.isFinite(n)?n:0;
}
async function answer(q,c){
  if(STATE.questionAnswered)return;
  STATE.questionAnswered=true;
  const answeredMs=responseTimeMs();
  clearQuestionTimer();
  disableChoices();
  const addScore=scoreForSelectedChoice(q,c);
  STATE.score+=addScore;
  if(q.type==="attack")STATE.attackScore+=addScore;
  else if(q.type==="defense")STATE.defenseScore+=addScore;
  STATE.logs.push({inning:q.inning,outs:q.outs,stage:q.stage,id:q.id,type:q.type,theme:q.theme,selected:c.text||"",score:addScore,explain:c.explain||"",answer_time_ms:answeredMs,timeout:false});
  recordMistakeReview(q,c,addScore);
  if(c.score===3)await showGoodJob(q.type);
  STATE.current++;
  if(STATE.current>=STATE.sequence.length)finishGame();
  else{
    const next=STATE.sequence[STATE.current];
    const prev=STATE.sequence[STATE.current-1];
    const title=sideStartTitle(next, prev);
    if(title)showTransitionTitle(title, renderQuestion);
    else renderQuestion();
  }
}
function disableChoices(){$("choices").querySelectorAll("button").forEach(b=>b.disabled=true)}
function showGoodJob(mode){return new Promise(resolve=>{const el=$("goodJobToast");if(!el){resolve();return}const sceneMode=mode==="defense"?"defense":"attack";el.className=`goodjob-toast ${sceneMode}`;el.style.display="block";el.style.opacity="1";void el.offsetWidth;el.classList.add("show");setTimeout(()=>{el.className="goodjob-toast";el.style.display="none";el.style.opacity="";resolve()},1500)})}
function showQuizMasterBonusLifeAnimation(){
  try{
    let el=document.getElementById("quizMasterBonusLifeOverlay");
    if(!el){
      el=document.createElement("div");
      el.id="quizMasterBonusLifeOverlay";
      el.className="qm-bonus-life-overlay";
      el.setAttribute("aria-hidden","true");
      el.innerHTML=`<div class="qm-bonus-life-heart" aria-hidden="true"><svg viewBox="0 0 32 29.6" xmlns="http://www.w3.org/2000/svg"><path d="M23.6,0c-2.7,0-5.1,1.4-6.6,3.6C15.5,1.4,13.1,0,10.4,0C4.7,0,0,4.7,0,10.4 c0,7.4,7.5,12.3,16,19.2c8.5-6.9,16-11.8,16-19.2C32,4.7,27.3,0,23.6,0z" fill="#dd0e2d"/></svg></div>`+
        `<div class="qm-bonus-life-band" aria-hidden="true"></div>`+
        `<div class="qm-bonus-life-text">野球博士デイリーライフをゲット!</div>`;
      document.body.appendChild(el);
    }
    el.classList.remove("show");
    void el.offsetWidth;
    el.classList.add("show");
    setTimeout(()=>{if(el)el.classList.remove("show");},2700);
  }catch(e){}
}
function finishGame(){
  clearQuestionTimer();
  const maxScore=maxScoreForCurrentGame();
  $("resultScore").textContent=`${STATE.score} / ${maxScore}点`;
  const isBasic=STATE.grade<=2;
  const r=isBasic?(STATE.score>=24?"基本バッチリ":STATE.score>=15?"基本練習中":"もう一度チャレンジ"):(STATE.score>=48?"守備・走塁職人":STATE.score>=40?"学年クリア":STATE.score>=27?"成長中":"基本練習から再挑戦");
  $("rank").textContent=r;
  const didClear=STATE.score>=GRADE_CLEAR_SCORE&&STATE.grade>=3;
  const nextGrade=Math.min(6,STATE.grade+1);
  // 新仕様: 通常ゲームを1日1回完了すると、その日だけ野球博士チャレンジのライフを+1（当日初回のみ）。
  const earnedQuizLife=(STATE.loggedIn&&STATE.playerId&&!STATE.adminMode&&!isAdminQuestionTestMode()&&typeof hasQuizMasterFeatureAccess==="function"&&hasQuizMasterFeatureAccess())?quizMasterGrantBonusLifeToday():false;
  const quizLifeMsg=earnedQuizLife?`<p class="unlock-note">野球博士チャレンジのライフが1つ増えました！（本日のみ・毎日24時にリセット）</p>`:"";
  if(earnedQuizLife)setTimeout(showQuizMasterBonusLifeAnimation,450);
  const adminMsg=isAdminQuestionTestMode()
    ?`<p class="unlock-note locked">管理者テストプレイのため、この結果はスコア・ランキング・間違い記録・学年解放に反映されません。</p>`
    :(STATE.adminMode?`<p class="unlock-note locked">管理者用モードのため、この結果は保存・ランキング反映・学年解放されません。</p>`:"");
  const unlockMsg=isAdminQuestionTestMode()
    ?`<p class="unlock-note locked">指定IDテストは1問確認用です。回答内容は通常成績に保存されません。</p>`
    :(isBasic
      ?`<p class="unlock-note">基本動作モード完了！10問の基本問題をくり返し練習しよう。</p>`
      :(didClear?(STATE.grade<6?`<p class="unlock-note">${GRADE_CLEAR_SCORE}点以上達成！この学年をクリアしました。次回から同じ守備位置で${nextGrade}年生が選べます。</p>`:`<p class="unlock-note">${GRADE_CLEAR_SCORE}点以上達成！この守備位置の6年生をクリアしました。</p>`):`<p class="unlock-note locked">${GRADE_CLEAR_SCORE}点以上で次の学年が開放されます。もう一度チャレンジしよう！</p>`));
  $("breakdown").innerHTML=isBasic?`<p>基本動作：${STATE.score}/${maxScore}点</p>${adminMsg}${unlockMsg}${quizLifeMsg}`:`<p>攻撃：${STATE.attackScore}/27点　守備：${STATE.defenseScore}/27点</p>${adminMsg}${unlockMsg}${quizLifeMsg}`;
  $("answerLog").innerHTML=STATE.logs.map(l=>`<div class="logrow"><b>${escapeHtml(l.inning)}</b><span>${escapeHtml(outsLabel(l.outs))} ${escapeHtml(stageLabel(l.stage))}<br>${(STATE.adminMode||isAdminQuestionTestMode())?`${escapeHtml(l.id)}：`:"選択："}${rubyHtml(l.selected)}<br><small>${rubyHtml(l.explain)}${l.answer_time_ms?`　回答時間：${(Number(l.answer_time_ms)/1000).toFixed(1)}秒`:""}</small></span><b>${escapeHtml(l.score)}点</b></div>`).join("");
  setScoreSaveNotice("",false);
  saveScore().then(data=>{
    if(data&&data.ok){
      setScoreSaveNotice("",false);
      if(didClear&&!isAdminQuestionTestMode()){
        const unlock=markGradeCompleted(STATE.position,STATE.grade);
        if(unlock){
          showUnlockAnimation(unlock);
        }
      }
    }else if(data&&data.adminMode){
      setScoreSaveNotice("",false);
    }else{
      setScoreSaveNotice("成績の保存に失敗しました。通信環境を確認し、もう一度プレイ結果をご確認ください。",true);
    }
  }).catch(()=>{
    setScoreSaveNotice("成績の保存に失敗しました。通信環境を確認し、もう一度プレイ結果をご確認ください。",true);
  });
  show("screen-result");
}
function setScoreSaveNotice(message,isError=false){
  const box=$("scoreSaveNotice");
  if(!box)return;
  box.textContent=message||"";
  box.classList.toggle("is-error",!!isError);
  box.style.display=message?"block":"none";
}

async function saveScore(){
  if(isAdminQuestionTestMode()){
    console.info("[admin-test] score save skipped");
    return {ok:false,adminMode:true,adminQuestionTest:true};
  }
  if(STATE.adminMode){
    console.info("admin mode: score save skipped");
    return {ok:false,adminMode:true};
  }
  try{
    const res=await fetch("api/save_score.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({player_id:STATE.playerId,client_token:getClientToken(),grade:STATE.grade,position:STATE.position,total_score:STATE.score,attack_score:STATE.attackScore,defense_score:STATE.defenseScore,max_score:maxScoreForCurrentGame(),logs:STATE.logs})});
    return await res.json();
  }catch(e){
    console.warn("score save skipped",e);
    return {ok:false,error:"score_save_failed"};
  }
}
const C={bases:{home:[960,858],first:[1360,684],second:[960,496],third:[560,684]},fielders:{P:[960,690],C:[960,930],"1B":[1400,650],"2B":[1160,525],SS:[760,525],"3B":[520,650],LF:[330,310],CF:[960,210],RF:[1590,310]},runners:{"1B":[1300,610],"2B":[880,520],"3B":[620,720],BR:[1110,800]}};
function labelForPos(p){if(p==="BASIC")return "基本動作";return STATE.config.positions[p]||p}

function playTarget(play){const map={"unknown_to_pitcher":"P","pitcher_grounder":"P","pitcher_fly":"P","pitcher":"P","second_grounder":"2B","second_fly":"2B","first_grounder":"1B","first_fly":"1B","first_base":"1B","short_grounder":"SS","short_fly":"SS","short_liner":"SS","third_fly":"3B","center_single":"CF","center_fly":"CF","center_shallow":"CF","passed_ball":"C","passed_ball_back_diagonal":"C","passed_ball_back_center":"C","passed_ball_back_3b_side":"C","wild_pitch_near_catcher":"C","one_bounce_before_home":"C","one_bounce_front_catcher":"C","one_bounce_feet_catcher":"C","home_throw":"C","home_throw_backstop":"C","outfield_home_throw_cf":"C","catcher_grounder":"C","catcher_fly":"C","pickoff":"P","pickoff_1b":"1B","pickoff_2b":"2B","pickoff_3b":"3B","pickoff_1b_overthrow_foul_deep":"1B","pickoff_2b_overthrow_center_deep":"2B","pickoff_3b_overthrow_foul_deep":"3B","throw_1b_over_high":"1B","left_single":"LF","left_line":"LF","left_fly":"LF","left_shallow":"LF","left_center_gap":"LF","right_line":"RF","right_fly":"RF","right_shallow":"RF","right_single":"RF","right_center_gap":"RF","bunt":"P","squeeze_bunt":"P","third_grounder":"3B","first_second_gap_grounder":"1B","first_second_gap_fly":"1B","second_short_gap_grounder":"SS","third_short_gap_grounder":"3B","third_short_gap_fly":"3B"};return map[play]||"P"}

function isLinerLike(q){
  const p=String((q.visual&&q.visual.ball_path)||"");
  const t=String(q.ball_tag||"");
  const s=String(q.situation||"");
  const pr=String(q.prompt||"");
  const th=String(q.theme||"");
  const story=`${t} ${s} ${pr} ${th}`;
  return /liner|ライナー/.test(p) || /ライナー/.test(story);
}
function isFlyLike(q){
  const p=String((q.visual&&q.visual.ball_path)||"");
  const t=String(q.ball_tag||"");
  const s=String(q.situation||"");
  const pr=String(q.prompt||"");
  const th=String(q.theme||"");
  const story=`${t} ${s} ${pr} ${th}`;
  return /fly|フライ|飛球/.test(p) || /フライ|飛球/.test(story);
}
function isGroundLike(q){
  const p=String((q.visual&&q.visual.ball_path)||"");
  const t=String(q.ball_tag||"");
  return /ground|ゴロ|bunt|バント/.test(p) || /ゴロ|バント/.test(t);
}
function shouldHideBallPath(q,hx,hy,tx,ty){
  const theme=String(q.theme||"");
  const tag=String(q.ball_tag||"");
  const situation=String(q.situation||"");
  const prompt=String(q.prompt||"");
  const story=`${tag} ${situation} ${prompt}`;
  const staticThemes=[
    "steal",
    "pitcher_pitch_location_vs_bunt",
    "pitcher_avoid_low_wild_pitch_runner_on_third",
    "pitcher_pitch_selection_by_batting_order",
    "pitcher_balk_rule_judgment",
    "pickoff_after_return",
    "low_grade_catcher_safe_steal_throw_setup",
    "catcher_steal_second_throw"
  ];
  if((q.visual&&q.visual.hide_ball_path) || staticThemes.includes(theme) || /盗塁/.test(tag)) return true;
  // v432: ピッチャー保持かつ対象もピッチャーでも、実際に打球がピッチャー付近へ来た問題では矢印を表示する。
  // 隠すのは、投球前・構え・気配など、まだボールインプレイでない静的な問題だけ。
  if(q.visual && q.visual.ball_holder === "P" && q.visual.target_position === "P"){
    const prePitchToPitcher=/気配|構え|投球動作|投げる前|まだピッチャーは投げていない|セットポジション|準備をしている|準備している/.test(story);
    const actualBattedBall=/打球|打った|打ち上げた|転がった|捕球|フライ|ゴロ|ライナー/.test(story);
    if(prePitchToPitcher && !actualBattedBall) return true;
  }
  if(/まだ本塁へ返球は来ていない|本塁を離れた/.test(story)) return true;
  if(hx===tx && hy===ty) return true;
  if(/まだピッチャーは投げていない|投げる前|セットポジション|構えを見せている|構えている|準備をしている|準備している|近くの野手が持っている/.test(story)) return true;
  return false;
}
function ballPathSvg(q,hx,hy,tx,ty){
  if(shouldHideBallPath(q,hx,hy,tx,ty)){
    return "";
  }
  if(isLinerLike(q)){
    const cx=(hx+tx)/2;
    const lift=Math.max(45, Math.min(95, Math.abs(hx-tx)*0.10 + Math.abs(hy-ty)*0.08));
    const cy=Math.min(hy,ty)-lift;
    return `<path d="M${hx} ${hy} Q ${cx} ${cy} ${tx} ${ty}" fill="none" stroke="#ffd400" stroke-width="8" stroke-dasharray="16 12" marker-end="url(#arrow)"/>`;
  }
  if(isFlyLike(q)){
    const cx=(hx+tx)/2;
    const lift=Math.max(130, Math.min(230, Math.abs(hx-tx)*0.18 + Math.abs(hy-ty)*0.16));
    const cy=Math.min(hy,ty)-lift;
    return `<path d="M${hx} ${hy} Q ${cx} ${cy} ${tx} ${ty}" fill="none" stroke="#ffd400" stroke-width="8" stroke-dasharray="20 14" marker-end="url(#arrow)"/>`;
  }
  return `<line x1="${hx}" y1="${hy}" x2="${tx}" y2="${ty}" stroke="#ffd400" stroke-width="8" stroke-dasharray="${isGroundLike(q)?'8 10':'20 14'}" marker-end="url(#arrow)"/>`;
}
function ballEndpoints(q){
  const play=String((q.visual&&q.visual.ball_path)||"");
  const target=playTarget(play);
  let [sx,sy]=C.bases.home;
  let [tx,ty]=C.fielders[target]||C.bases.first;

  // v393: 暴投・ワイルドピッチ・悪送球・本塁返球の専用表示座標
  // 座標はSVG固定座標。xは左=三塁側 / 右=一塁側、yは下=本塁後方。
  const backstopCenter=[960,1035];
  const backstopSlightDiagonal=[1015,1025];
  const backstopThirdBaseSide=[900,1030];
  const nearCatcher=[960,965];
  const oneBounceBeforeHome=[960,825];
  const oneBounceFrontCatcher=[960,900];
  const oneBounceFeetCatcher=[960,925];
  const firstFoulDeep=[1510,735];
  const firstOverHigh=[1500,610];
  const secondCenterDeep=[960,360];
  const thirdFoulDeep=[410,735];

  const thirdShortGap=[(C.fielders["3B"][0]+C.fielders.SS[0])/2,(C.fielders["3B"][1]+C.fielders.SS[1])/2];
  const secondShortGap=[(C.fielders["2B"][0]+C.fielders.SS[0])/2,(C.fielders["2B"][1]+C.fielders.SS[1])/2];
  const firstSecondGap=[(C.fielders["1B"][0]+C.fielders["2B"][0])/2,(C.fielders["1B"][1]+C.fielders["2B"][1])/2];

  if(play==="passed_ball_back_diagonal"){
    [sx,sy]=C.fielders.P; [tx,ty]=backstopSlightDiagonal;
  }else if(play==="passed_ball_back_center"){
    [sx,sy]=C.fielders.P; [tx,ty]=backstopCenter;
  }else if(play==="passed_ball_back_3b_side"){
    [sx,sy]=C.fielders.P; [tx,ty]=backstopThirdBaseSide;
  }else if(play==="wild_pitch_near_catcher"){
    [sx,sy]=C.fielders.P; [tx,ty]=nearCatcher;
  }else if(play==="one_bounce_before_home"){
    [sx,sy]=C.fielders.P; [tx,ty]=oneBounceBeforeHome;
  }else if(play==="one_bounce_front_catcher"){
    [sx,sy]=C.fielders.P; [tx,ty]=oneBounceFrontCatcher;
  }else if(play==="one_bounce_feet_catcher"){
    [sx,sy]=C.fielders.P; [tx,ty]=oneBounceFeetCatcher;
  }else if(play==="pickoff_1b_overthrow_foul_deep"){
    [sx,sy]=C.fielders.P; [tx,ty]=firstFoulDeep;
  }else if(play==="pickoff_2b_overthrow_center_deep"){
    [sx,sy]=C.fielders.P; [tx,ty]=secondCenterDeep;
  }else if(play==="pickoff_3b_overthrow_foul_deep"){
    [sx,sy]=C.fielders.P; [tx,ty]=thirdFoulDeep;
  }else if(play==="throw_1b_over_high"){
    [sx,sy]=C.fielders.P; [tx,ty]=firstOverHigh;
  }else if(play==="home_throw_backstop"){
    [sx,sy]=C.fielders.P; [tx,ty]=backstopCenter;
  }else if(play==="outfield_home_throw_cf"){
    [sx,sy]=C.fielders.CF; [tx,ty]=C.fielders.C;
  }else if(play==="first_second_gap_grounder" || play==="first_second_gap_fly"){
    [tx,ty]=firstSecondGap;
  }else if(play==="second_short_gap_grounder"){
    [tx,ty]=secondShortGap;
  }else if(play==="third_short_gap_grounder" || play==="third_short_gap_fly"){
    [tx,ty]=thirdShortGap;
  }else if(play==="right_center_gap"){
    tx=(C.fielders.CF[0]+C.fielders.RF[0])/2;
    ty=(C.fielders.CF[1]+C.fielders.RF[1])/2;
  }else if(play==="left_center_gap"){
    tx=(C.fielders.LF[0]+C.fielders.CF[0])/2;
    ty=(C.fielders.LF[1]+C.fielders.CF[1])/2;
  }
  if(/^pickoff_/.test(play) && !/overthrow/.test(play)){
    [sx,sy]=C.fielders.P;
    if(target==="1B")[tx,ty]=C.bases.first;
    else if(target==="2B")[tx,ty]=C.bases.second;
    else if(target==="3B")[tx,ty]=C.bases.third;
  }
  return {sx,sy,tx,ty};
}

function mobileFieldViewBox(){if(window.innerWidth<=720)return "260 120 1400 860";return "0 0 1920 1080"}
function fielderLabel(p){
  const q = STATE && STATE.sequence ? STATE.sequence[STATE.current] : null;
  if(q && q.type === "defense" && p === STATE.position) return "自分";
  return labelForPos(p);
}

function batterDisplayOptions(q){
  const theme = String((q&&q.theme)||"");
  const visual = (q&&q.visual)||{};
  const batterHanded = String(visual.batter_handed||"");
  const isLeft = batterHanded === "L" || /left_handed|left_batter_shift/.test(theme) || /左打者|左バッター/.test(`${q&&q.ball_tag||""} ${q&&q.situation||""}`);
  return isLeft ? {x:1010,y:850,flipX:true} : {x:910,y:850,flipX:false};
}
function makeFieldSvg(q){const runners=(q.visual&&q.visual.runners)||[];const hasBR=!!(q&&(((q.visual&&q.visual.batter_runner===true)&&(q.stage==="BR"||isBattedBallSituation(q)))||(q.type==="attack"&&shouldShowBatterRunner(q))));const {sx,sy,tx,ty}=ballEndpoints(q);const fielderDrawOrder=["LF","CF","RF","SS","2B","3B","P","1B","C"];const showBatter=!!(!hasBR&&((q.visual&&q.visual.show_batter)||shouldShowBatter(q)));const batterOpts=batterDisplayOptions(q);const playersSvg=`${fielderDrawOrder.map(p=>playerSvg(C.fielders[p][0],C.fielders[p][1],fielderLabel(p),"fielder",p)).join("")}${runners.map(r=>playerSvg(C.runners[r][0],C.runners[r][1],runnerLabel(r),"runner",r)).join("")}${hasBR?playerSvg(C.runners.BR[0],C.runners.BR[1],"打者走者","runner","BR"):""}${showBatter?playerSvg(batterOpts.x,batterOpts.y,"バッター","runner","BATTER",batterOpts):""}`;const arrowSvg=ballPathSvg(q,sx,sy,tx,ty);return `<svg viewBox="${mobileFieldViewBox()}" role="img" aria-label="固定座標野球場"><defs><marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L10,5 L0,10z" fill="#ffd400"/></marker><filter id="shadow"><feDropShadow dx="2" dy="3" stdDeviation="2" flood-opacity=".35"/></filter></defs><rect width="1920" height="1080" fill="#7dbb59"/><path d="M960 880 L1390 680 L960 480 L530 680 Z" fill="#c98745"/><circle cx="960" cy="690" r="95" fill="#c98745"/><path d="M960 880 L1390 680 M960 880 L530 680" fill="none" stroke="#fff" stroke-width="8"/><path d="M960 880 L1750 520 M960 880 L170 520" stroke="#fff" stroke-width="6"/>${baseSvg("home","本塁")}${baseSvg("first","一塁")}${baseSvg("second","二塁")}${baseSvg("third","三塁")}<g class="playersLayer">${playersSvg}</g><g class="ballPathLayer" pointer-events="none">${arrowSvg}</g><style>.svgLabel{font:700 28px sans-serif;paint-order:stroke;stroke:#fff;stroke-width:6px;fill:#111}.scoreText{font:800 25px sans-serif;fill:#fff}.ballPathLayer{isolation:isolate}</style></svg>`}
function baseSvg(key,label){const [x,y]=C.bases[key];if(key==="home")return `<polygon points="${x-22},${y-10} ${x+22},${y-10} ${x+30},${y+2} ${x},${y+22} ${x-30},${y+2}" fill="#fff" stroke="#ddd" stroke-width="3"/><text x="${x+39}" y="${y+14}" class="svgLabel">${label}</text>`;return `<polygon points="${x},${y-16} ${x+30},${y} ${x},${y+16} ${x-30},${y}" fill="#fff" stroke="#ddd" stroke-width="3"/><text x="${x+32}" y="${y-14}" class="svgLabel">${label}</text>`}


function scoreboardSvg(){return `<g transform="translate(1160 35)">
  <rect x="0" y="0" width="700" height="155" rx="12" fill="#062819" stroke="#1d4a35" stroke-width="8"/>
  <g font-family="Arial, sans-serif" font-weight="900" fill="#fff" text-anchor="middle">
    <text x="72" y="42" font-size="28" text-anchor="middle">TEAM</text>
    <text x="265" y="42" font-size="28">1</text>
    <text x="330" y="42" font-size="28">2</text>
    <text x="395" y="42" font-size="28">3</text>
    <text x="475" y="42" font-size="28">R</text>
    <text x="540" y="42" font-size="28">H</text>
    <text x="605" y="42" font-size="28">E</text>

    <text x="38" y="88" font-size="30" text-anchor="start">ファイターズ</text>
    <text x="265" y="88" font-size="30">0</text>
    <text x="330" y="88" font-size="30">0</text>
    <text x="395" y="88" font-size="30">0</text>
    <text x="475" y="88" font-size="30">0</text>
    <text x="540" y="88" font-size="30">0</text>
    <text x="605" y="88" font-size="30">0</text>

    <text x="38" y="130" font-size="30" text-anchor="start">パイレーツ</text>
    <text x="265" y="130" font-size="30">0</text>
    <text x="330" y="130" font-size="30">0</text>
    <text x="395" y="130" font-size="30">0</text>
    <text x="475" y="130" font-size="30">0</text>
    <text x="540" y="130" font-size="30">0</text>
    <text x="605" y="130" font-size="30">0</text>
  </g>
</g>`}
function escapeXml(s){return String(s).replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]))}

























function runnerLabel(r){
  const q = STATE && STATE.sequence ? STATE.sequence[STATE.current] : null;
  if(q && q.type === "attack"){
    if(q.stage === r) return "自分";
    if(r === "BR" && q.stage === "BR") return "自分";
  }
  return r==="1B" ? "一塁ランナー" : r==="2B" ? "二塁ランナー" : r==="3B" ? "三塁ランナー" : "打者走者";
}

function sideColorFor(kind, code){
  const q = STATE && STATE.sequence ? STATE.sequence[STATE.current] : null;
  const isOffense = (kind === "runner" || code === "BR" || code === "BATTER");
  if(!q) return "blue";
  if(q.type === "attack") return isOffense ? "red" : "blue";
  return isOffense ? "blue" : "red";
}

function spriteHrefFor(kind, code, label){
  const color = sideColorFor(kind, code);
  if(kind === "fielder" && code === "C") return `assets/sprite_catcher_${color}.webp?v=393`;
  if(kind === "fielder") return `assets/sprite_fielder_${color}.webp?v=393`;
  if(code === "BATTER") return `assets/sprite_batter_${color}.webp?v=393`;
  if(code === "BR") return `assets/sprite_batterrunner_${color}.webp?v=393`;
  if(label === "自分"){
    const q = STATE && STATE.sequence ? STATE.sequence[STATE.current] : null;
    if(q && q.stage === "1B") return `assets/sprite_runner1_${color}.webp?v=393`;
    if(q && q.stage === "2B") return `assets/sprite_runner2_${color}.webp?v=393`;
    if(q && q.stage === "3B") return `assets/sprite_runner3_${color}.webp?v=393`;
  }
  if(code === "1B") return `assets/sprite_runner1_${color}.webp?v=393`;
  if(code === "2B") return `assets/sprite_runner2_${color}.webp?v=393`;
  if(code === "3B") return `assets/sprite_runner3_${color}.webp?v=393`;
  return `assets/sprite_runner1_${color}.webp?v=393`;
}

function isBattedBallSituation(q){
  const txt = `${q.ball_tag||""} ${q.situation||""} ${q.theme||""}`;
  if(/牽制|偽投|ワイルドピッチ|暴投|捕逸|パスボール/.test(txt)) return false;
  if(/構え|投球動作に入った|投げていない|投げる前|セットに入った/.test(txt)) return false;
  return /ヒット|ゴロ|フライ|ライナー|打球|打った|転がった|打ち上げた/.test(txt);
}

function shouldShowBatter(q){
  if(!q || q.type !== "attack") return false;
  if(q.stage === "BR") return false;
  return !isBattedBallSituation(q);
}

function shouldShowBatterRunner(q){
  if(!q || q.type !== "attack") return false;
  return q.stage === "BR" || isBattedBallSituation(q);
}

function playerSvg(x,y,label,kind,code,opts={}){
  const href = spriteHrefFor(kind, code, label);
  const isCatcher = kind === "fielder" && code === "C";
  const imgW = isCatcher ? 94 : 88;
  const imgH = isCatcher ? 118 : 110;
  const ix = x - imgW / 2;
  const iy = y - 78;
  const labelText = escapeXml(label);
  const emph = labelText === "自分";
  const labelSvg = emph
    ? `<g><rect x="${x-52}" y="${y+52}" width="104" height="44" rx="10" fill="#fff" stroke="#d60000" stroke-width="5"/><text x="${x}" y="${y+84}" text-anchor="middle" font-size="34" font-weight="900" fill="#d60000" stroke="#fff" stroke-width="2" paint-order="stroke">自分</text></g>`
    : `<text x="${x}" y="${y+68}" text-anchor="middle" class="svgLabel">${labelText}</text>`;
  const imageTransform = opts.flipX ? ` transform="translate(${2*x} 0) scale(-1 1)"` : "";
  return `<g filter="url(#shadow)" class="playerLayer" data-sprite="${href}">
    <image href="${href}" x="${ix}" y="${iy}" width="${imgW}" height="${imgH}" preserveAspectRatio="xMidYMid meet"${imageTransform}/>
    ${labelSvg}
  </g>`;
}



function playArrowFromTo(q, POS){
  // default arrows from current ball holder toward relevant target
  const ball = q.ball || "";
  const stage = q.stage || "";
  const tag = q.ball_tag || "";
  const situation = q.situation || "";
  const theme = q.theme || "";

  // map codes to field points
  const pointOf = (code) => {
    if(code==="P") return POS.P;
    if(code==="C") return POS.C;
    if(code==="1B") return POS.B1;
    if(code==="2B") return POS.B2;
    if(code==="3B") return POS.B3;
    if(code==="SS") return POS.SS;
    if(code==="LF") return POS.LF;
    if(code==="CF") return POS.CF;
    if(code==="RF") return POS.RF;
    return POS.C;
  };

  // who currently starts the action arrow?
  // 牽制は投手からの送球。暴投は投手→本塁付近。返球は野手→各塁/本塁を優先。
  let fromCode = ball || "C";
  let toCode = "1B";

  // pickoff /牽制
  if(/牽制/.test(tag) || /牽制/.test(situation) || /pickoff/.test(theme)){
    fromCode = "P";
    if(stage === "1B") toCode = "1B";
    else if(stage === "2B") toCode = "2B";
    else if(stage === "3B") toCode = "3B";
    else toCode = "1B";
    return {from: pointOf(fromCode), to: pointOf(toCode)};
  }

  // wild pitch / passed ball
  if(/暴投|ワイルドピッチ/.test(tag) || /暴投|ワイルドピッチ/.test(situation)){
    return {from: pointOf("P"), to: pointOf("C")};
  }

  // pickoff return = after pitcher throws to a base, return throw back toward pitcher
  if(/pickoff_return/.test(theme)){
    if(stage === "1B") return {from: pointOf("P"), to: pointOf("1B")};
    if(stage === "2B") return {from: pointOf("P"), to: pointOf("2B")};
    if(stage === "3B") return {from: pointOf("P"), to: pointOf("3B")};
    return {from: pointOf("P"), to: pointOf("1B")};
  }

  // general fallback by current ball location
  if(ball === "1B") return {from: pointOf("1B"), to: pointOf("P")};
  if(ball === "2B") return {from: pointOf("2B"), to: pointOf("P")};
  if(ball === "3B") return {from: pointOf("3B"), to: pointOf("P")};
  if(ball === "SS") return {from: pointOf("SS"), to: pointOf("1B")};
  if(ball === "P") return {from: pointOf("P"), to: pointOf("C")};
  if(ball === "C") return {from: pointOf("C"), to: pointOf("1B")};

  return {from: pointOf(fromCode), to: pointOf(toCode)};
}

init();





/* v504: 苦手チェックをサーバー保存し、引き継ぎIDで機種変更対応 */
function mistakeTransferStorageKey(pid){
  return `baseballMistakeTransferId:${pid||STATE.playerId||"guest"}`;
}
function currentMistakeTransferId(pid){
  return localStorage.getItem(mistakeTransferStorageKey(pid||STATE.playerId))||"";
}
function setMistakeTransferId(id,pid){
  const v=String(id||"").trim().toUpperCase();
  if(v)localStorage.setItem(mistakeTransferStorageKey(pid||STATE.playerId),v);
}
function mergeMistakeReviewData(localData,serverData){
  const merged={items:{...(localData&&localData.items?localData.items:{})}};
  const serverItems=(serverData&&serverData.items)?serverData.items:{};
  Object.entries(serverItems).forEach(([key,remote])=>{
    const local=merged.items[key];
    if(!local){
      merged.items[key]=remote;
      return;
    }
    const localDate=String(local.lastAnsweredAt||local.lastMissedAt||"");
    const remoteDate=String(remote.lastAnsweredAt||remote.lastMissedAt||"");
    merged.items[key]={
      ...local,
      ...remote,
      missCount:Math.max(Number(local.missCount||0),Number(remote.missCount||0)),
      tryCount:Math.max(Number(local.tryCount||0),Number(remote.tryCount||0)),
      mastered:!!local.mastered||!!remote.mastered,
      firstMissedAt:local.firstMissedAt||remote.firstMissedAt||"",
      lastMissedAt:(String(remote.lastMissedAt||"")>String(local.lastMissedAt||""))?remote.lastMissedAt:local.lastMissedAt,
      lastAnsweredAt:remoteDate>localDate?remote.lastAnsweredAt:local.lastAnsweredAt,
      tags:Array.from(new Set([...(local.tags||[]),...(remote.tags||[])]))
    };
  });
  return merged;
}
async function syncMistakesToServer(silent=true){
  if(isAdminQuestionTestMode())return null;
  if(!isMistakeReviewEnabled() || !STATE.playerId)return null;
  const data=loadMistakeReview(STATE.playerId);
  try{
    const res=await fetch("api/save_mistakes.php",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({
        player_id:STATE.playerId,
        client_token:getClientToken(),
        transfer_id:currentMistakeTransferId(STATE.playerId),
        mistakes:data
      })
    });
    const json=await res.json().catch(()=>({ok:false,error:"invalid response"}));
    if(res.ok&&json&&json.ok){
      if(json.transfer_id)setMistakeTransferId(json.transfer_id,STATE.playerId);
      if(!silent)alert(`引き継ぎIDを保存しました：${json.transfer_id}`);
      renderMistakeReviewSection();
      return json;
    }
    if(!silent)alert("引き継ぎIDの保存に失敗しました。");
  }catch(e){
    console.warn("mistake sync failed",e);
    if(!silent)alert("通信エラーで保存できませんでした。");
  }
  return null;
}
async function loadOwnServerMistakes(pid){
  if(!isMistakeReviewEnabled() || !pid)return;
  const transferId=currentMistakeTransferId(pid);
  try{
    const body=transferId?{transfer_id:transferId}:{player_id:pid,client_token:getClientToken()};
    const res=await fetch("api/get_mistakes.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)});
    const json=await res.json().catch(()=>({ok:false}));
    if(res.ok&&json&&json.ok&&json.mistakes){
      if(json.transfer_id)setMistakeTransferId(json.transfer_id,pid);
      const merged=mergeMistakeReviewData(loadMistakeReview(pid),json.mistakes);
      saveMistakeReview(merged,pid);
      renderMistakeReviewSection();
    }
  }catch(e){
    console.warn("server mistakes load skipped",e);
  }
}
async function issueMistakeTransferId(){
  if(!isFeatureUnlocked("device_transfer")){alert("端末引継ぎ機能は招待IDで解放すると利用できます。");return;}

  if(!STATE.playerId){
    alert("プレイヤーIDを入力してから利用してください。");
    return;
  }
  const r=await syncMistakesToServer(false);
  if(r&&r.transfer_id){
    const input=$("mistakeTransferInput");
    if(input)input.value=r.transfer_id;
  }
}
async function copyMistakeTransferId(){
  if(!isFeatureUnlocked("device_transfer")){alert("端末引継ぎ機能は招待IDで解放すると利用できます。");return;}

  const id=currentMistakeTransferId(STATE.playerId);
  if(!id){
    await issueMistakeTransferId();
    return;
  }
  try{
    await navigator.clipboard.writeText(id);
    alert("引き継ぎIDをコピーしました。");
  }catch(e){
    alert(`引き継ぎID：${id}`);
  }
}
async function importMistakeTransferFromInput(){
  if(!isFeatureUnlocked("device_transfer")){alert("端末引継ぎ機能は招待IDで解放すると利用できます。");return;}

  const input=$("mistakeTransferInput");
  const code=String(input&&input.value||"").trim().toUpperCase();
  if(!code){
    alert("引き継ぎIDを入力してください。");
    return;
  }
  if(!STATE.playerId){
    alert("先にプレイヤーIDを入力してください。");
    return;
  }
  try{
    const res=await fetch("api/get_mistakes.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({transfer_id:code,player_id:STATE.playerId,client_token:getClientToken()})});
    const json=await res.json().catch(()=>({ok:false}));
    if(!res.ok||!json.ok||!json.mistakes){
      alert("引き継ぎIDが見つかりませんでした。");
      return;
    }
    const merged=mergeMistakeReviewData(loadMistakeReview(STATE.playerId),json.mistakes);
    saveMistakeReview(merged,STATE.playerId);
    setMistakeTransferId(code,STATE.playerId);
    await syncMistakesToServer(true);
    renderMistakeReviewSection();
    alert("まちがえた問題データを引き継ぎました。");
  }catch(e){
    console.warn("mistake import failed",e);
    alert("通信エラーで引き継ぎできませんでした。");
  }
}

/* v504: v502の記録関数をサーバー同期付きで上書き */


/* v534: 引き継ぎUIをオプション機能化。
   device_transferが未解放のIDでは、マイページに端末引継ぎUIを表示しない。 */




/* v535: 未解放時はオプション機能UIを完全非表示。
   - mistake_review未解放: 間違いプレイチェック欄を表示しない
   - device_transfer未解放: 端末引継ぎUIを表示しない */



function updateOptionFeatureVisibility(){
  const mistakeUnlocked=isMistakeReviewFeatureUnlocked();
  const toggle=$("mistakeReviewToggle");
  const status=$("mistakeReviewStatus");
  const label=toggle?toggle.closest(".admin-toggle"):null;
  const section=status?status.closest(".settings-section"):null;
  // 既存HTMLでは同じsettings-section内に管理者用モードもあるため、個別要素だけ非表示にする
  if(label)label.style.display=mistakeUnlocked?"flex":"none";
  if(status)status.style.display=mistakeUnlocked?"block":"none";
  if(toggle&&!mistakeUnlocked){
    toggle.checked=false;
    toggle.disabled=true;
  }
  document.body.classList.toggle("feature-mistake-review-unlocked",!!mistakeUnlocked);
  document.body.classList.toggle("feature-device-transfer-unlocked",!!isFeatureUnlocked("device_transfer"));
}
