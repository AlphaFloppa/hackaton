const themeToggle = $('#theme-toggle');
const root = document.documentElement;
themeToggle.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'));
themeToggle.addEventListener('click', () => {
  const dark = root.dataset.theme !== 'dark';
  root.dataset.theme = dark ? 'dark' : 'light';
  themeToggle.setAttribute('aria-pressed', String(dark));
  try {
    localStorage.setItem('mesh-pvz-theme', root.dataset.theme);
  } catch {
    // The theme remains active in this tab.
  }
});

if (apiBase) {
  const refreshButton = document.createElement('button');
  refreshButton.className = 'b';
  refreshButton.dataset.a = 'refresh';
  refreshButton.textContent = 'Обновить данные';
  document.querySelector('header').append(refreshButton);
  document
    .querySelectorAll('[data-a="reset"], [data-a="nokey"]')
    .forEach((button) => button.remove());
  refresh(true);
} else {
  seed();
  render();
}
