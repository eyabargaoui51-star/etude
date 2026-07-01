/* ============================================
   SmartTeacher — Main JS
   ============================================ */

   document.addEventListener('DOMContentLoaded', () => {
    initMobileSidebar();
    initCalendarDropdown();
    initNavSelection();
    initModalSystem();
    initLogout();
  });
  
  /* ---- Mobile sidebar open/close ---- */
  function initMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const openBtn = document.getElementById('mobileMenuBtn');
  
    if (!sidebar || !overlay || !openBtn) return;
  
    const openSidebar = () => {
      sidebar.classList.add('is-open');
      overlay.classList.add('is-visible');
      document.body.style.overflow = 'hidden';
    };
  
    const closeSidebar = () => {
      sidebar.classList.remove('is-open');
      overlay.classList.remove('is-visible');
      document.body.style.overflow = '';
    };
  
    openBtn.addEventListener('click', openSidebar);
    overlay.addEventListener('click', closeSidebar);
  
    // Close sidebar automatically when a nav link is tapped on mobile
    sidebar.querySelectorAll('.nav-link').forEach((link) => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 860) closeSidebar();
      });
    });
  
    // Close on resize back to desktop
    window.addEventListener('resize', () => {
      if (window.innerWidth > 860) closeSidebar();
    });
  }
  
  /* ---- Functional calendar dropdown ---- */
  function initCalendarDropdown() {
    const dropdown = document.getElementById('dateDropdown');
    const trigger = document.getElementById('datePillBtn');
    const popover = document.getElementById('calendarPopover');
    const monthLabel = document.getElementById('calMonthLabel');
    const grid = document.getElementById('calendarGrid');
    const prevBtn = document.getElementById('calPrev');
    const nextBtn = document.getElementById('calNext');
    const todayBtn = document.getElementById('calToday');
    const dateEl = document.getElementById('todayDate');
    const dayEl = document.getElementById('todayDay');
  
    if (!dropdown || !trigger || !popover || !grid) return;
  
    const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
      'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    const DAY_NAMES = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
  
    const today = new Date();
    let viewYear = today.getFullYear();
    let viewMonth = today.getMonth();
    let selectedDate = new Date(today);
  
    function updatePillText(date) {
      dateEl.textContent = `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`;
      dayEl.textContent = DAY_NAMES[date.getDay()];
    }
  
    function renderCalendar() {
      monthLabel.textContent = `${MONTHS[viewMonth]} ${viewYear}`;
      grid.innerHTML = '';
  
      const firstOfMonth = new Date(viewYear, viewMonth, 1);
      // Convert Sunday(0)-based getDay() to Monday-first index (0 = Monday)
      const startOffset = (firstOfMonth.getDay() + 6) % 7;
      const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
      const daysInPrevMonth = new Date(viewYear, viewMonth, 0).getDate();
  
      const cells = [];
  
      // Leading days from previous month
      for (let i = startOffset - 1; i >= 0; i--) {
        cells.push({ day: daysInPrevMonth - i, muted: true });
      }
      // Days of current month
      for (let d = 1; d <= daysInMonth; d++) {
        cells.push({ day: d, muted: false });
      }
      // Trailing days to complete the grid (multiple of 7)
      while (cells.length % 7 !== 0) {
        cells.push({ day: cells.length - (startOffset + daysInMonth) + 1, muted: true });
      }
  
      cells.forEach((cell) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'calendar-day';
        btn.textContent = cell.day;
  
        if (cell.muted) {
          btn.classList.add('calendar-day--muted');
        } else {
          const cellDate = new Date(viewYear, viewMonth, cell.day);
          const isToday = cellDate.toDateString() === today.toDateString();
          const isSelected = cellDate.toDateString() === selectedDate.toDateString();
  
          if (isToday) btn.classList.add('calendar-day--today');
          if (isSelected) btn.classList.add('calendar-day--selected');
  
          btn.addEventListener('click', () => {
            selectedDate = cellDate;
            updatePillText(selectedDate);
            renderCalendar();
            closePopover();
          });
        }
  
        grid.appendChild(btn);
      });
    }
  
    function openPopover() {
      popover.hidden = false;
      dropdown.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      viewYear = selectedDate.getFullYear();
      viewMonth = selectedDate.getMonth();
      renderCalendar();
    }
  
    function closePopover() {
      popover.hidden = true;
      dropdown.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
    }
  
    function togglePopover() {
      if (popover.hidden) openPopover();
      else closePopover();
    }
  
    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      togglePopover();
    });
  
    prevBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      viewMonth -= 1;
      if (viewMonth < 0) { viewMonth = 11; viewYear -= 1; }
      renderCalendar();
    });
  
    nextBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      viewMonth += 1;
      if (viewMonth > 11) { viewMonth = 0; viewYear += 1; }
      renderCalendar();
    });
  
    todayBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      selectedDate = new Date(today);
      updatePillText(selectedDate);
      viewYear = today.getFullYear();
      viewMonth = today.getMonth();
      renderCalendar();
      closePopover();
    });
  
    popover.addEventListener('click', (e) => e.stopPropagation());
  
    document.addEventListener('click', (e) => {
      if (!dropdown.contains(e.target)) closePopover();
    });
  
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closePopover();
    });
  
    // Initialize pill with today's real date
    updatePillText(selectedDate);
  }
  
  /* ---- Sidebar active state on click ---- */
  function initNavSelection() {
    const items = document.querySelectorAll('.nav-item');
    items.forEach((item) => {
      item.querySelector('.nav-link')?.addEventListener('click', (e) => {
        e.preventDefault();
        items.forEach((i) => i.classList.remove('nav-item--active'));
        item.classList.add('nav-item--active');
      });
    });
  }
  
  /* ---- Logout confirmation ---- */
  function initLogout() {
    const btn = document.querySelector('.logout-btn');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const confirmed = window.confirm('Voulez-vous vraiment vous déconnecter ?');
      if (confirmed) {
        console.log('Déconnexion en cours…');
        // window.location.href = 'login.html';
      }
    });
  }
  
  /* ============================================
     MOCK DATA
     ============================================ */
  const FILIERES = ['Informatique', 'Mathématiques', 'Sciences', 'Technique'];
  const GROUPES = ['Info A', 'Info B', 'Info C', 'Math A', 'Math B', 'Science A', 'Science B', 'Tech A'];
  
  const STUDENTS = [
    { id: 1, nom: 'Ben Ali', prenom: 'Ahmed', filiere: 'Informatique', groupe: 'Info A' },
    { id: 2, nom: 'Mahjoub', prenom: 'Youssef', filiere: 'Mathématiques', groupe: 'Math B' },
    { id: 3, nom: 'Bouzid', prenom: 'Sara', filiere: 'Sciences', groupe: 'Science A' },
    { id: 4, nom: 'Salah', prenom: 'Mohamed', filiere: 'Technique', groupe: 'Tech A' },
    { id: 5, nom: 'Trabelsi', prenom: 'Ines', filiere: 'Informatique', groupe: 'Info B' },
    { id: 6, nom: 'Jaziri', prenom: 'Karim', filiere: 'Informatique', groupe: 'Info B' },
    { id: 7, nom: 'Chemli', prenom: 'Ilyes', filiere: 'Sciences', groupe: 'Science A' },
    { id: 8, nom: 'Nasri', prenom: 'Rania', filiere: 'Mathématiques', groupe: 'Math A' },
    { id: 9, nom: 'Belhaj', prenom: 'Hedi', filiere: 'Informatique', groupe: 'Info A' },
    { id: 10, nom: 'Bouaicha', prenom: 'Nour', filiere: 'Technique', groupe: 'Tech A' },
  ];
  
  /* ============================================
     MODAL SYSTEM
     ============================================ */
  function initModalSystem() {
    const overlay = document.getElementById('modalOverlay');
    const box = document.getElementById('modalBox');
    const closeBtn = document.getElementById('modalClose');
    const cancelBtn = document.getElementById('modalCancel');
    const form = document.getElementById('modalForm');
    const titleEl = document.getElementById('modalTitle');
    const iconEl = document.getElementById('modalIcon');
    const fieldsEl = document.getElementById('modalFields');
    const selectorWrap = document.getElementById('modalStudentSelector');
    const studentListEl = document.getElementById('studentList');
    const studentSearchEl = document.getElementById('studentSearch');
    const selectedCountEl = document.getElementById('selectedCount');
  
    if (!overlay || !form) return;
  
    let currentAction = null;
    let selectedStudentIds = new Set();
  
    const studentOptions = () => STUDENTS.map((s) => ({
      value: String(s.id),
      label: `${s.prenom} ${s.nom} — ${s.groupe}`,
    }));
  
    /* ---- Action registry: each entry defines a modal form ---- */
    const ACTIONS = {
      addStudent: {
        title: 'Ajouter un élève',
        icon: '👤',
        submitLabel: "Ajouter l'élève",
        fields: [
          { name: 'nom', label: 'Nom', type: 'text', required: true, placeholder: 'ex: Ben Ali' },
          { name: 'prenom', label: 'Prénom', type: 'text', required: true, placeholder: 'ex: Ahmed' },
          { name: 'tel', label: 'Numéro de téléphone', type: 'tel', required: true, placeholder: 'ex: 22 345 678' },
          { name: 'filiere', label: 'Filière', type: 'select', required: true, options: FILIERES },
          { name: 'groupe', label: 'Groupe', type: 'select', required: true, options: GROUPES },
        ],
        onSubmit: (data) => {
          const newId = STUDENTS.length ? Math.max(...STUDENTS.map((s) => s.id)) + 1 : 1;
          STUDENTS.push({ id: newId, nom: data.nom, prenom: data.prenom, filiere: data.filiere, groupe: data.groupe });
          showToast(`✅ ${data.prenom} ${data.nom} a été ajouté(e) au groupe ${data.groupe}.`);
        },
      },
  
      createGroup: {
        title: 'Créer un groupe',
        icon: '👥',
        submitLabel: 'Créer le groupe',
        fields: [
          { name: 'nomGroupe', label: 'Nom du groupe', type: 'text', required: true, placeholder: 'ex: Info C' },
          { name: 'filiere', label: 'Filière', type: 'select', required: true, options: FILIERES },
        ],
        withStudentSelector: true,
        onSubmit: (data, studentIds) => {
          studentIds.forEach((id) => {
            const student = STUDENTS.find((s) => s.id === id);
            if (student) student.groupe = data.nomGroupe;
          });
          const count = studentIds.length;
          showToast(`✅ Groupe « ${data.nomGroupe} » créé avec ${count} élève${count > 1 ? 's' : ''}.`);
        },
      },
  
      newSession: {
        title: 'Nouvelle séance',
        icon: '🗓️',
        submitLabel: 'Planifier la séance',
        fields: [
          { name: 'groupe', label: 'Groupe', type: 'select', required: true, options: GROUPES },
          { name: 'matiere', label: 'Matière / Sujet', type: 'text', required: true, placeholder: 'ex: Physique' },
          { name: 'date', label: 'Date', type: 'date', required: true },
          { name: 'heure', label: 'Heure', type: 'time', required: true },
          { name: 'salle', label: 'Salle (optionnel)', type: 'text', required: false, placeholder: 'ex: Salle 3' },
        ],
        onSubmit: (data) => {
          showToast(`✅ Séance de ${data.matiere} planifiée pour ${data.groupe} le ${data.date} à ${data.heure}.`);
        },
      },
  
      addPayment: {
        title: 'Enregistrer un paiement',
        icon: '💳',
        submitLabel: 'Enregistrer',
        fields: [
          { name: 'eleve', label: 'Élève', type: 'select', required: true, options: studentOptions },
          { name: 'montant', label: 'Montant (DT)', type: 'number', required: true, placeholder: 'ex: 120' },
          { name: 'date', label: 'Date', type: 'date', required: true },
          { name: 'statut', label: 'Statut', type: 'select', required: true, options: ['Payé', 'Non payé'] },
        ],
        onSubmit: (data) => {
          const student = STUDENTS.find((s) => String(s.id) === data.eleve);
          const name = student ? `${student.prenom} ${student.nom}` : 'Élève';
          showToast(`✅ Paiement de ${data.montant} DT enregistré pour ${name} (${data.statut}).`);
        },
      },
  
      exportReport: {
        title: 'Exporter un rapport',
        icon: '📄',
        submitLabel: 'Exporter',
        fields: [
          { name: 'type', label: 'Type de rapport', type: 'select', required: true, options: ['Présences', 'Paiements', 'Notes', 'Groupes'] },
          { name: 'dateDebut', label: 'Date début', type: 'date', required: true },
          { name: 'dateFin', label: 'Date fin', type: 'date', required: true },
          { name: 'format', label: 'Format', type: 'select', required: true, options: ['PDF', 'Excel'] },
        ],
        onSubmit: (data) => {
          showToast(`✅ Rapport "${data.type}" (${data.format}) du ${data.dateDebut} au ${data.dateFin} en cours d'export.`);
        },
      },
  
      addFiliere: {
        title: 'Ajouter une filière',
        icon: '🗂️',
        submitLabel: 'Ajouter la filière',
        fields: [
          { name: 'nomFiliere', label: 'Nom de la filière', type: 'text', required: true, placeholder: 'ex: Bac Sport' },
          { name: 'niveau', label: 'Niveau', type: 'select', required: true, options: ['1ère année', '2ème année', 'Bac'] },
          { name: 'nbGroupes', label: 'Nombre de groupes prévus', type: 'number', required: false, placeholder: 'ex: 2' },
        ],
        onSubmit: (data) => {
          showToast(`✅ Filière « ${data.nomFiliere} » ajoutée.`);
        },
      },
  
      markPresence: {
        title: 'Marquer une présence',
        icon: '⬇️',
        submitLabel: 'Enregistrer',
        fields: [
          { name: 'eleve', label: 'Élève', type: 'select', required: true, options: studentOptions },
          { name: 'date', label: 'Date', type: 'date', required: true },
          { name: 'statut', label: 'Statut', type: 'select', required: true, options: ['Présent', 'Absent', 'Retard'] },
        ],
        onSubmit: (data) => {
          const student = STUDENTS.find((s) => String(s.id) === data.eleve);
          const name = student ? `${student.prenom} ${student.nom}` : 'Élève';
          showToast(`✅ ${name} marqué « ${data.statut} » le ${data.date}.`);
        },
      },
  
      addRevision: {
        title: 'Ajouter une révision',
        icon: '🔁',
        submitLabel: 'Ajouter',
        fields: [
          { name: 'titre', label: 'Titre', type: 'text', required: true, placeholder: 'ex: Révision Mécanique' },
          { name: 'groupe', label: 'Groupe', type: 'select', required: true, options: GROUPES },
          { name: 'date', label: 'Date', type: 'date', required: true },
          { name: 'description', label: 'Description (optionnel)', type: 'textarea', required: false },
        ],
        onSubmit: (data) => {
          showToast(`✅ Révision « ${data.titre} » ajoutée pour ${data.groupe}.`);
        },
      },
  
      filterStats: {
        title: 'Filtrer les statistiques',
        icon: '📊',
        submitLabel: 'Appliquer',
        fields: [
          { name: 'filiere', label: 'Filière', type: 'select', required: false, options: FILIERES },
          { name: 'groupe', label: 'Groupe', type: 'select', required: false, options: GROUPES },
          { name: 'dateDebut', label: 'Date début', type: 'date', required: true },
          { name: 'dateFin', label: 'Date fin', type: 'date', required: true },
        ],
        onSubmit: (data) => {
          showToast(`✅ Statistiques filtrées du ${data.dateDebut} au ${data.dateFin}.`);
        },
      },
  
      editSettings: {
        title: 'Paramètres du compte',
        icon: '⚙️',
        submitLabel: 'Enregistrer',
        fields: [
          { name: 'nom', label: 'Nom complet', type: 'text', required: true, placeholder: 'ex: Professeur Physique' },
          { name: 'email', label: 'Email', type: 'text', required: true, placeholder: 'ex: prof@coficab.tn' },
          { name: 'tel', label: 'Téléphone', type: 'tel', required: false, placeholder: 'ex: 22 345 678' },
        ],
        onSubmit: () => {
          showToast('✅ Paramètres du compte mis à jour.');
        },
      },
    };
  
    /* ---- Field rendering ---- */
    function buildField(field) {
      const wrap = document.createElement('div');
      wrap.className = 'modal__field';
  
      const label = document.createElement('label');
      label.className = 'modal__field-label';
      label.htmlFor = `field-${field.name}`;
      label.innerHTML = field.required
        ? `${field.label}<span class="required-mark">*</span>`
        : field.label;
      wrap.appendChild(label);
  
      let input;
      if (field.type === 'select') {
        input = document.createElement('select');
        input.className = 'modal__select';
        const placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = 'Sélectionner…';
        placeholderOpt.disabled = true;
        placeholderOpt.selected = true;
        input.appendChild(placeholderOpt);
  
        const resolvedOptions = typeof field.options === 'function' ? field.options() : field.options;
        resolvedOptions.forEach((opt) => {
          const optEl = document.createElement('option');
          if (typeof opt === 'object') {
            optEl.value = opt.value;
            optEl.textContent = opt.label;
          } else {
            optEl.value = opt;
            optEl.textContent = opt;
          }
          input.appendChild(optEl);
        });
      } else if (field.type === 'textarea') {
        input = document.createElement('textarea');
        input.className = 'modal__textarea';
        if (field.placeholder) input.placeholder = field.placeholder;
      } else {
        input = document.createElement('input');
        input.className = 'modal__input';
        input.type = field.type;
        if (field.placeholder) input.placeholder = field.placeholder;
      }
  
      input.id = `field-${field.name}`;
      input.name = field.name;
      if (field.required) input.required = true;
  
      const error = document.createElement('span');
      error.className = 'modal__field-error';
      error.textContent = 'Ce champ est requis.';
  
      wrap.appendChild(input);
      wrap.appendChild(error);
      return wrap;
    }
  
    /* ---- Student selector rendering ---- */
    function renderStudentList(filterText) {
      const term = (filterText || '').trim().toLowerCase();
      const filtered = STUDENTS.filter((s) =>
        `${s.prenom} ${s.nom} ${s.groupe} ${s.filiere}`.toLowerCase().includes(term)
      );
  
      studentListEl.innerHTML = '';
  
      if (!filtered.length) {
        const empty = document.createElement('div');
        empty.className = 'student-row__empty';
        empty.textContent = 'Aucun élève trouvé.';
        studentListEl.appendChild(empty);
        return;
      }
  
      filtered.forEach((s) => {
        const row = document.createElement('label');
        row.className = 'student-row';
  
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = selectedStudentIds.has(s.id);
        checkbox.addEventListener('change', () => {
          if (checkbox.checked) selectedStudentIds.add(s.id);
          else selectedStudentIds.delete(s.id);
          updateSelectedCount();
        });
  
        const info = document.createElement('span');
        info.className = 'student-row__info';
        info.innerHTML = `<span class="student-row__name">${s.prenom} ${s.nom}</span><span class="student-row__meta">${s.groupe} · ${s.filiere}</span>`;
  
        row.appendChild(checkbox);
        row.appendChild(info);
        studentListEl.appendChild(row);
      });
    }
  
    function updateSelectedCount() {
      const n = selectedStudentIds.size;
      selectedCountEl.textContent = `${n} élève${n > 1 ? 's' : ''} sélectionné${n > 1 ? 's' : ''}`;
    }
  
    /* ---- Open / close ---- */
    function openModal(actionKey) {
      const config = ACTIONS[actionKey];
      if (!config) return;
  
      currentAction = config;
      selectedStudentIds = new Set();
  
      titleEl.textContent = config.title;
      iconEl.textContent = config.icon;
      fieldsEl.innerHTML = '';
      config.fields.forEach((f) => fieldsEl.appendChild(buildField(f)));
  
      document.getElementById('modalSubmit').textContent = config.submitLabel || 'Valider';
  
      if (config.withStudentSelector) {
        selectorWrap.hidden = false;
        studentSearchEl.value = '';
        renderStudentList('');
        updateSelectedCount();
      } else {
        selectorWrap.hidden = true;
      }
  
      form.querySelectorAll('.modal__field--invalid').forEach((el) => el.classList.remove('modal__field--invalid'));
  
      overlay.hidden = false;
      document.body.style.overflow = 'hidden';
      setTimeout(() => fieldsEl.querySelector('input, select, textarea')?.focus(), 50);
    }
  
    function closeModal() {
      overlay.hidden = true;
      document.body.style.overflow = '';
      currentAction = null;
      form.reset();
    }
  
    /* ---- Wire trigger elements ---- */
    document.querySelectorAll('[data-action]').forEach((el) => {
      const actionKey = el.getAttribute('data-action');
      if (!actionKey) return; // empty data-action (e.g. Dashboard link) does nothing
      el.addEventListener('click', (e) => {
        e.preventDefault();
        openModal(actionKey);
      });
    });
  
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !overlay.hidden) closeModal();
    });
  
    studentSearchEl.addEventListener('input', () => renderStudentList(studentSearchEl.value));
  
    /* ---- Submit handling ---- */
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!currentAction) return;
  
      let isValid = true;
      const data = {};
  
      currentAction.fields.forEach((field) => {
        const inputEl = document.getElementById(`field-${field.name}`);
        const fieldWrap = inputEl.closest('.modal__field');
        const value = inputEl.value.trim();
  
        if (field.required && !value) {
          isValid = false;
          fieldWrap.classList.add('modal__field--invalid');
        } else {
          fieldWrap.classList.remove('modal__field--invalid');
        }
        data[field.name] = value;
      });
  
      if (currentAction.withStudentSelector && selectedStudentIds.size === 0) {
        isValid = false;
        selectedCountEl.style.color = 'var(--color-red)';
        selectedCountEl.textContent = 'Sélectionnez au moins un élève.';
      } else if (selectedCountEl) {
        selectedCountEl.style.color = '';
      }
  
      if (!isValid) return;
  
      currentAction.onSubmit(data, Array.from(selectedStudentIds));
      closeModal();
    });
  }
  
  /* ============================================
     TOAST NOTIFICATIONS
     ============================================ */
  function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
  
    const toast = document.createElement('div');
    toast.className = `toast${type === 'error' ? ' toast--error' : ''}`;
    toast.textContent = message;
    container.appendChild(toast);
  
    setTimeout(() => {
      toast.classList.add('is-leaving');
      setTimeout(() => toast.remove(), 200);
    }, 3500);
  }