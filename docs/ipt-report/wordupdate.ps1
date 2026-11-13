$path = (Resolve-Path "IPT_Report_Boniface_Chrispin_Magali.docx").Path
$pdf = $path -replace '\.docx$', '.pdf'
$w = New-Object -ComObject Word.Application
$w.Visible = $false; $w.DisplayAlerts = 0
try {
  $d = $w.Documents.Open($path)
  $d.Fields.Update() | Out-Null
  foreach ($t in $d.TablesOfContents) { $t.Update() }
  foreach ($t in $d.TablesOfFigures) { $t.Update() }
  $d.Fields.Update() | Out-Null
  "pages: " + $d.ComputeStatistics(2)
  $d.Save()
  $d.ExportAsFixedFormat($pdf, 17)
  $d.Close()
} finally { $w.Quit() }
