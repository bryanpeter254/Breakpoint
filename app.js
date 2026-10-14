const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let me = null;

async function api(action, data) {
  const r = await fetch('api.php?action=' + action, data ? {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data)
  } : {});
  const j = await r.json().catch(() => ({error: 'Server error'}));
  if (!r.ok) { toast(j.error || 'Something went wrong', true); throw j; }
  return j;
}
function toast(t, bad) {
  const m = $('#msg'); m.textContent = t; m.className = bad ? 'err' : ''; m.style.display = 'block';
  setTimeout(() => m.style.display = 'none', 3000);
}
const val = id => document.getElementById(id).value;
const list = (items, fn, empty) => items.length ? items.map(fn).join('') : `<p>${empty}</p>`;
const field = (id, label, type = 'text') => `<label for="${id}">${label}</label><input id="${id}" type="${type}">`;
const area = (id, label, v = '') => `<label for="${id}">${label}</label><textarea id="${id}" rows="2">${esc(v)}</textarea>`;
const json = s => { try { return typeof s === 'string' ? JSON.parse(s) : s; } catch { return null; } };

async function start() {
  try { me = await fetch('api.php?action=me').then(r => r.ok ? r.json() : null); } catch { me = null; }
  me ? dashboard() : authView();
}

/* ---------- auth ---------- */
function authView() {
  $('#who').innerHTML = '';
  $('#app').innerHTML = `
  <div class="card"><h2>Sign in</h2>
    ${field('lp', 'Phone (07XX XXX XXX)', 'tel')}${field('lw', 'Password', 'password')}
    <button onclick="login()">Sign in</button></div>
  <div class="card"><h2>New patient? Create an account</h2>
    ${field('rn', 'Full name')}${field('rp', 'Phone (used for M-Pesa and login)', 'tel')}
    ${field('ri', 'National ID number')}${field('rd', 'Date of birth', 'date')}
    <label for="rg">Gender</label><select id="rg"><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select>
    ${field('rc', 'County (e.g. Mombasa)')}${field('rv', 'Village / area (e.g. Changamwe)')}
    ${field('rh', 'Chronic conditions (comma separated, e.g. asthma, diabetes)')}${field('ra', 'Allergies')}
    ${field('rw', 'Password (8+ characters)', 'password')}
    <button onclick="register()">Create account</button></div>`;
}
async function login() { await api('login', {phone: val('lp'), password: val('lw')}); start(); }
async function register() {
  await api('register', {full_name: val('rn'), phone: val('rp'), id_number: val('ri'), dob: val('rd'), gender: val('rg'),
    county: val('rc'), village: val('rv'), chronic_conditions: val('rh'), allergies: val('ra'), password: val('rw')});
  toast('Account created. You can sign in now.');
}
async function logout() { await api('logout', {}); start(); }

async function dashboard() {
  $('#who').innerHTML = `${esc(me.name)} (${me.role}) <button class="alt" onclick="logout()">Sign out</button>`;
  await ({patient: patientView, doctor: doctorView, admin: adminView})[me.role]();
}

/* ---------- consultation detail (all roles) ---------- */
async function detail(id) {
  const d = await api('consult_detail&id=' + id), c = d.consult, n = d.notes || {};
  const mine = me.role === 'doctor' && c.doctor_id == me.id && c.status === 'in_progress';
  const triage = json(c.triage_notes), conds = (json(c.chronic_conditions) || []).join(', ');
  $('#detail').innerHTML = `<div class="card"><h2>Consultation #${c.id} - ${esc(c.patient)}</h2>
    <p><span class="pill">${c.triage_level || '-'}</span> <span class="pill">${c.status}</span> ${c.consult_type}
      ${c.agora_channel_id && c.status === 'in_progress' ? ' · channel: ' + esc(c.agora_channel_id) : ''}<br>
      <strong>Complaint:</strong> ${esc(c.chief_complaint)}<br>
      ${me.role !== 'patient' ? `<strong>Chronic:</strong> ${esc(conds) || 'none'} · <strong>Allergies:</strong> ${esc(c.allergies) || 'none'}<br>` : ''}
      ${triage && triage.matched ? 'Triage flag: ' + esc(triage.matched) : ''}</p>
    <h2>Notes (SOAP)</h2>
    ${mine ? `${area('ns', 'Subjective', n.subjective)}${area('no', 'Objective', n.objective)}${area('na', 'Assessment', n.assessment)}${area('np', 'Plan', n.plan)}
      <button onclick="saveNotes(${c.id})">Save notes</button>`
      : d.notes ? `<p><strong>S:</strong> ${esc(n.subjective)}<br><strong>O:</strong> ${esc(n.objective)}<br><strong>A:</strong> ${esc(n.assessment)}<br><strong>P:</strong> ${esc(n.plan)}</p>` : '<p>No notes yet.</p>'}
    <h2>Prescriptions</h2>
    ${list(d.prescriptions, p => `<div class="row"><div><strong>${esc(p.medicine_name)}</strong> ${esc(p.dosage)}, ${esc(p.frequency)} (qty ${p.quantity})
      ${p.instructions_swahili ? '<br><em>' + esc(p.instructions_swahili) + '</em>' : ''}</div>
      <span class="pill">${p.is_dispensed ? 'dispensed' + (p.pharmacy ? ' - ' + esc(p.pharmacy) : '') : 'not dispensed'}</span></div>`, 'None.')}
    ${mine ? `${field('pm', 'Medicine')}${field('pd', 'Dosage (e.g. 500mg)')}${field('pf', 'Frequency (e.g. 2x daily for 5 days)')}${field('pq', 'Quantity', 'number')}
      ${field('ps', 'Maelekezo (Swahili instructions)')}<button onclick="prescribe(${c.id})">Add prescription</button>` : ''}
    <h2>Lab tests</h2>
    ${list(d.labs, l => `<div class="row"><div><strong>${esc(l.test_name)}</strong> ${l.result_value ? '- ' + esc(l.result_value) : ''}
      ${l.result_pdf_url ? ` <a href="${esc(l.result_pdf_url)}" target="_blank" rel="noopener">PDF</a>` : ''}</div>
      <div class="acts"><span class="pill">${l.status}</span>${me.role !== 'patient' && l.status === 'requested' ? `<button class="alt" onclick="labResult(${l.id})">Enter result</button>` : ''}</div></div>`, 'None.')}
    ${mine ? `${field('lt', 'Request a test (e.g. Malaria BST)')}<button onclick="labRequest(${c.id})">Request test</button>
      <button class="alt" onclick="completeConsult(${c.id})">Complete consultation</button>` : ''}
  </div>`;
  $('#detail').scrollIntoView({behavior: 'smooth'});
}
async function saveNotes(id) {
  await api('save_notes', {consultation_id: id, subjective: val('ns'), objective: val('no'), assessment: val('na'), plan: val('np')}); toast('Notes saved');
}
async function prescribe(id) {
  await api('prescribe', {consultation_id: id, medicine_name: val('pm'), dosage: val('pd'), frequency: val('pf'), quantity: val('pq'), instructions_swahili: val('ps')});
  toast('Prescription added'); detail(id);
}
async function labRequest(id) { await api('lab_request', {consultation_id: id, test_name: val('lt')}); toast('Test requested'); detail(id); }
async function labResult(id) {
  const r = prompt('Result value'); if (!r) return;
  await api('lab_result', {lab_request_id: id, result_value: r, result_pdf_url: prompt('PDF link (optional)') || ''});
  toast('Result saved'); dashboard();
}
async function completeConsult(id) { if (confirm('Complete this consultation?')) { await api('complete', {consultation_id: id}); dashboard(); } }

/* ---------- patient ---------- */
async function patientView() {
  const cs = await api('my_consults');
  $('#app').innerHTML = `<div id="detail"></div>
  <div class="card"><h2>Start a consultation</h2>
    ${field('cc', 'What is the problem? (e.g. Fever 3 days)')}
    <label for="ct">How would you like to consult?</label><select id="ct"><option value="video">Video</option><option value="audio">Audio</option><option value="chat">Chat</option></select>
    <button onclick="startConsult()">Start (KES 300)</button></div>
  <div class="card"><h2>My consultations</h2>${list(cs, c => `
    <div class="row"><div><span class="pill">${c.triage_level || '-'}</span> <strong>${esc(c.chief_complaint)}</strong><br>
      ${esc(c.created_at)} · ${c.consult_type}${c.doctor ? ' · Dr. ' + esc(c.doctor) : ''}</div>
      <div class="acts"><span class="pill">${c.status}</span>
        ${c.payment_status === 'unpaid' ? (c.pay_status === 'pending' ? '<span class="pill">payment checking</span>' : `<button class="alt" onclick="pay(${c.id},${c.consultation_fee})">Pay via M-Pesa</button>`) : ''}
        <button class="alt" onclick="detail(${c.id})">View</button></div></div>`, 'No consultations yet.')}</div>`;
}
async function startConsult() {
  const r = await api('start_consult', {chief_complaint: val('cc'), consult_type: val('ct')});
  toast(r.triage_level === 'RED' ? 'Emergency flagged - a doctor will be alerted. If severe, go to the nearest hospital now.' : 'Consultation created. Please pay to join the queue.');
  dashboard();
}
async function pay(id, fee) {
  const code = prompt(`Pay KES ${fee} by M-Pesa, then enter the confirmation code (e.g. QJ12ABC345)`); if (!code) return;
  await api('pay', {consultation_id: id, mpesa_code: code}); toast('Code received. Waiting for confirmation.'); dashboard();
}

/* ---------- doctor ---------- */
async function doctorView() {
  const q = await api('queue');
  $('#app').innerHTML = `<div id="detail"></div>
  <div class="card"><h2>Consultation queue</h2><button class="alt" onclick="toggleAvail()">Toggle availability</button>
  ${list(q, c => `<div class="row"><div><span class="pill">${c.triage_level || '-'}</span> <strong>${esc(c.patient)}</strong> (${c.age}y) - ${esc(c.chief_complaint)}<br>${c.consult_type} · ${c.status}</div>
    <div class="acts">${c.doctor_id ? `<button class="alt" onclick="detail(${c.id})">Open</button>` : `<button class="alt" onclick="accept(${c.id})">Accept</button>`}</div></div>`, 'No patients waiting.')}</div>`;
}
async function accept(id) { await api('accept', {id}); toast('Consultation accepted'); await doctorView(); detail(id); }
async function toggleAvail() { const r = await api('availability', {}); toast(r.available ? 'You are available' : 'You are offline'); }

/* ---------- admin ---------- */
async function adminView() {
  const o = await api('admin_overview');
  $('#app').innerHTML = `
  <div class="card"><h2>Payments to confirm</h2>${list(o.payments, p => `
    <div class="row"><div><strong>${esc(p.mpesa_code)}</strong> - KES ${esc(p.amount)}<br>${esc(p.patient)}</div>
    <button class="alt" onclick="confirmPay(${p.id})">Confirm received</button></div>`, 'Nothing pending.')}</div>
  <div class="card"><h2>Prescriptions to dispense</h2>${list(o.prescriptions, p => `
    <div class="row"><div><strong>${esc(p.medicine_name)}</strong> ${esc(p.dosage)}, ${esc(p.frequency)} (qty ${p.quantity})<br>${esc(p.patient)}</div>
    <div class="acts"><select id="ph${p.id}">${o.pharmacies.map(h => `<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select>
    <button class="alt" onclick="dispense(${p.id})">Mark dispensed</button></div></div>`, 'Nothing to dispense.')}</div>
  <div class="card"><h2>Add doctor</h2>
    ${field('dn', 'Full name')}${field('dp', 'Phone', 'tel')}${field('dk', 'KMPDC number')}${field('ds', 'Specialty (default General)')}
    ${field('dl', 'Licence document link')}${field('dw', 'Temporary password (8+ characters)', 'password')}
    <button onclick="addDoctor()">Add doctor</button></div>`;
}
async function confirmPay(id) { await api('confirm_payment', {id}); toast('Payment confirmed'); dashboard(); }
async function dispense(id) { await api('dispense', {id, pharmacy_id: val('ph' + id)}); toast('Marked dispensed'); dashboard(); }
async function addDoctor() {
  await api('add_doctor', {full_name: val('dn'), phone: val('dp'), kmpdc_number: val('dk'), specialty: val('ds'), license_doc_url: val('dl'), password: val('dw')});
  toast('Doctor added'); dashboard();
}

start();
