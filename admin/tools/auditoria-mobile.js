/* Auditoria mobile — colar no console do navegador (logado no admin).
   Abre cada tela num iframe de 375px e lista: estouro horizontal, elementos
   culpados, alvos de toque < 44px e inputs com fonte < 16px (zoom no iOS).
   Só faz GET em telas de listagem/detalhe — NUNCA inclua URLs de ação
   (excluir, importar, sincronizar, logout). */
(async () => {
  const W = 375;
  const telas = [
    'index.php', 'clientes.php', 'vendas.php', 'financeiro.php', 'avaliacoes.php',
    'pendencias_pos_venda.php', 'whatsapp_inbox.php', 'produtividade.php', 'origem_leads.php',
    'qualidade_ia.php', 'veiculos.php', 'usuarios.php', 'auditoria.php', 'backup.php', 'saude.php',
    'configuracoes.php', 'meu_perfil.php', 'vendas_inbox.php', 'financeiro_inbox.php',
    'financeiro-lancamentos.php', 'financeiro-relatorios.php', 'financeiro-categorias.php',
    'financeiro-fornecedores.php', 'financeiro-colaboradores.php',
    // detalhes: troque os ids por registros que existam
    'oportunidade.php?id=349', 'cliente_detalhe.php?id=337', 'venda.php?id=1',
    'avaliacao.php?id=4', 'veiculo_midias.php?id=44',
  ];
  const sel = e => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.classList.length ? '.' + [...e.classList].slice(0, 2).join('.') : '');
  const linhas = [];
  for (const p of telas) {
    const f = document.createElement('iframe');
    f.style.cssText = `position:fixed;left:0;top:0;width:${W}px;height:800px;opacity:0;pointer-events:none;z-index:-1`;
    f.src = '/admin/' + p;
    document.body.appendChild(f);
    await new Promise(r => { f.onload = r; setTimeout(r, 15000); });
    await new Promise(r => setTimeout(r, 600));
    const d = f.contentDocument, vw = d.documentElement.clientWidth;
    const culpados = new Set();
    d.querySelectorAll('body *').forEach(e => {
      if (e.closest('.tabela-scroll,.etapas-nav,.topbar-menu,thead')) return;
      const r = e.getBoundingClientRect();
      if (r.width && r.right > vw + 1 && !(e.parentElement.getBoundingClientRect().right > vw + 1)) culpados.add(sel(e));
    });
    const toque = [...d.querySelectorAll('main a, main button, main input:not([type=hidden]), main select')]
      .filter(e => { const r = e.getBoundingClientRect(); return r.width && r.height && r.height < 40; }).length;
    const fonte = [...d.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=hidden]),select,textarea')]
      .filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).length;
    linhas.push({ tela: p, largura: d.documentElement.scrollWidth + '/' + vw, estouro: d.documentElement.scrollWidth > vw, toquePequeno: toque, fonteMenor16: fonte, culpados: [...culpados].slice(0, 5).join(' ') });
    f.remove();
  }
  console.table(linhas);
})();
