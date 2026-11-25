try {
  if (localStorage.getItem('mesh-pvz-theme') === 'dark') {
    document.documentElement.dataset.theme = 'dark';
  }
} catch {
  // The browser can block storage for local files.
}
