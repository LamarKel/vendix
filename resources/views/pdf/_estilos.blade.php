<style>
    @page { margin: 14mm 15mm 16mm 15mm; size: letter; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5px; color: #1F2937; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    p { margin: 0; }
    .franja { height: 5px; background: {{ $cp }}; margin-bottom: 12px; }
    .encabezado td { vertical-align: top; }
    .logo { max-width: 120px; max-height: 64px; margin-bottom: 4px; }
    .emp-nombre { font-size: 15px; font-weight: bold; color: {{ $cs }}; }
    .emp-dato { font-size: 8.5px; color: #4B5563; margin-top: 1px; }
    .doc-caja { border: 1px solid {{ $cpBorde }}; background: {{ $cpSuave }}; padding: 9px 12px; }
    .doc-titulo { font-size: 15px; font-weight: bold; color: {{ $cp }}; letter-spacing: 1px; }
    .doc-numero { font-size: 13px; font-weight: bold; color: {{ $cs }}; margin-top: 3px; }
    .doc-dato { font-size: 8.5px; color: #4B5563; margin-top: 2px; }
    .doc-dato b { color: #1F2937; }
    .bloque { margin-top: 12px; }
    .bloque td { vertical-align: top; }
    .etiqueta { font-size: 7.5px; font-weight: bold; color: #6B7280; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 3px; }
    .caja { border: 1px solid #E5E7EB; padding: 8px 10px; }
    .caja .fuerte { font-size: 10.5px; font-weight: bold; color: {{ $cs }}; }
    .lineas { margin-top: 12px; }
    .lineas th { background: {{ $cs }}; color: #fff; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; padding: 6px 7px; text-align: left; }
    .lineas td { padding: 6px 7px; border-bottom: 1px solid #EEF0F3; font-size: 9px; vertical-align: top; }
    .lineas tr.alt td { background: #F9FAFB; }
    .num, .lineas th.num { text-align: right; white-space: nowrap; }
    .codigo { color: #9CA3AF; font-size: 7.5px; }
    .resumen { margin-top: 10px; }
    .resumen td { vertical-align: top; }
    .totales td { padding: 3px 8px; font-size: 9px; }
    .totales .gran td { background: {{ $cp }}; color: #fff; font-size: 12px; font-weight: bold; padding: 8px; }
    .nota { margin-top: 12px; font-size: 8.5px; color: #4B5563; }
    .aviso { margin-top: 12px; border: 1px solid #F59E0B; background: #FFFBEB; color: #92400E; padding: 7px 9px; font-size: 8.5px; text-align: center; font-weight: bold; }
    .anulada { border: 2px solid #DC2626; color: #DC2626; font-weight: bold; text-align: center; padding: 6px; margin-bottom: 10px; letter-spacing: 3px; font-size: 12px; }
    .pie { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7.5px; color: #9CA3AF; border-top: 1px solid #E5E7EB; padding-top: 4px; }
</style>
