<?php
/**
 * Generate IT Department Progress Report PDF for Ultimate General Trading.
 * Run: php reports/generate_industrial_attachment_report.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$logoPath = $root . '/images/ULTIMATE GENERAL LOGO.white_page-0001-cropped.svg';
$outputPath = __DIR__ . '/Industrial_Attachment_Report_Simple_Font.pdf';

if (!is_file($logoPath)) {
    fwrite(STDERR, "Logo not found: {$logoPath}\n");
    exit(1);
}

$logoSrc = str_replace('\\', '/', $logoPath);

$html = <<<'HTML'
<style>
body { font-family: dejavusans, Arial, sans-serif; font-size: 11pt; color: #1e293b; line-height: 1.55; }
h1 { font-size: 20pt; color: #0f172a; margin: 0 0 6px; }
h2 { font-size: 13pt; color: #0f172a; margin: 22px 0 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
h3 { font-size: 11.5pt; color: #334155; margin: 14px 0 6px; }
.subtitle { font-size: 12pt; color: #475569; margin: 0 0 18px; }
.meta-table { width: 100%; border-collapse: collapse; margin: 16px 0 24px; font-size: 10.5pt; }
.meta-table td { padding: 7px 10px; border: 1px solid #cbd5e1; vertical-align: top; }
.meta-table td.label { width: 28%; background: #f8fafc; font-weight: bold; color: #334155; }
p { margin: 0 0 10px; text-align: justify; }
ul { margin: 6px 0 12px 18px; padding: 0; }
li { margin-bottom: 5px; }
.week-table, .challenge-table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; font-size: 10.5pt; }
.week-table th, .challenge-table th { background: #f1f5f9; color: #334155; font-weight: bold; text-align: left; padding: 8px 10px; border: 1px solid #cbd5e1; }
.week-table td, .challenge-table td { padding: 8px 10px; border: 1px solid #cbd5e1; vertical-align: top; }
.week-table td:first-child, .challenge-table td:first-child { width: 12%; font-weight: bold; white-space: nowrap; }
.section-note { background: #f8fafc; border-left: 3px solid #f59e0b; padding: 10px 12px; margin: 12px 0; font-size: 10.5pt; }
.signature-block { margin-top: 36px; font-size: 11pt; }
.signature-line { border-bottom: 1px solid #334155; height: 28px; margin-top: 6px; width: 72%; }
.footer-note { font-size: 9pt; color: #64748b; margin-top: 8px; }
.cover-title-wrap { text-align: center; margin-top: 48px; margin-bottom: 28px; }
</style>

<h2>1.0 Executive Summary</h2>
<p>
  This report summarizes the activities, achievements, and technical progress delivered over the past 10 weeks as an IT employee at
  <strong>Ultimate General Trading</strong>. The work focused on system development, IT operations, and continuous improvement of the
  company's ERP platform to support daily business activities across departments.
</p>
<p>
  In my role, I participated in system analysis, user support, feature development, and maintenance of the organization's ERP
  platform. Key achievements included the development of a desktop ERP application, implementation of a web-based customer data
  collection module, establishment of an automated data backup mechanism, and foundational work on a new Performance Module. These
  contributions improved system reliability, usability, and the organization's capacity to support daily business operations.
</p>

<h2>2.0 Major Achievements and Technical Contributions</h2>

<h3>2.1 System Analysis and Requirements Gathering</h3>
<p>
  Conducted structured analysis of existing workflows through interviews, observation sessions, and feedback meetings with colleagues
  across departments. This helped identify bottlenecks, recurring errors, and usability gaps within the ERP system.
</p>

<h3>2.2 System Improvement Planning</h3>
<p>
  Developed a prioritized improvement plan based on collected feedback, focusing first on critical bug fixes and high-impact
  enhancements expected to improve operational efficiency and user satisfaction.
</p>

<h3>2.3 Data Backup and Security Implementation</h3>
<p>
  Designed and implemented an automated backup mechanism to protect organizational data against loss or corruption, thereby
  strengthening data integrity and business continuity.
</p>

<h3>2.4 Performance Module Initialization</h3>
<p>
  Commenced foundational work for a new Performance Module, including definition of key performance indicators (KPIs), workflow
  requirements, and preliminary database schema design.
</p>

<h3>2.5 Customer Acquisition Integration</h3>
<p>
  Integrated a system for collecting and aggregating customer data from online sources to support marketing activities and sales
  lead generation.
</p>

<h3>2.6 ERP Desktop Application Development</h3>
<p>
  Developed a functional desktop version of the web-based ERP to support dedicated workstation use and improved access for daily
  users. Continued deployment, version management, and rollout of upgrades and enhancements.
</p>

<h3>2.7 Training and Support Coordination</h3>
<p>
  Followed up on identified display and usability findings, prepared training materials, and coordinated resources required for
  effective user training sessions across Sales and Accounting departments.
</p>

<h2>3.0 10-Week Work Plan (Executed)</h2>
<table class="week-table">
  <thead><tr><th>Week</th><th>Key Activities</th></tr></thead>
  <tbody>
    <tr><td>1</td><td>System review and user feedback collection; aligned on ERP modules, workflows, and IT priorities.</td></tr>
    <tr><td>2</td><td>Problem analysis and training preparation; prioritized issues and prepared training materials.</td></tr>
    <tr><td>3</td><td>User training and technical support for Sales and Accounting users.</td></tr>
    <tr><td>4</td><td>Navigation improvement; analyzed and improved back/return functionality across key modules.</td></tr>
    <tr><td>5</td><td>Notification feature design for pending approvals and signing tasks.</td></tr>
    <tr><td>6</td><td>Notification implementation and testing across different user scenarios and roles.</td></tr>
    <tr><td>7</td><td>Bug fixing and system improvements based on reported issues and user feedback.</td></tr>
    <tr><td>8</td><td>System maintenance and performance monitoring; reviewed error logs and system response times.</td></tr>
    <tr><td>9</td><td>User feedback review and final improvements based on post-implementation experience.</td></tr>
    <tr><td>10</td><td>Final testing, documentation, evaluation, and preparation for management review.</td></tr>
  </tbody>
</table>

<h3>Continuous Activities Throughout the 10 Weeks</h3>
<ul>
  <li>Monitoring system performance and availability.</li>
  <li>Fixing bugs and errors reported by users.</li>
  <li>Providing day-to-day technical support.</li>
  <li>Performing minor system improvements and UI refinements.</li>
  <li>Documenting system changes, issues, and resolutions.</li>
  <li>Collecting feedback for future enhancements.</li>
</ul>

<div class="section-note">
  <strong>Overall Objective:</strong> To improve system usability, train users effectively, develop requested features, resolve
  existing problems, and ensure the ERP system remains reliable, secure, and well maintained.
</div>

<h2>4.0 Challenges and Solutions</h2>
<table class="challenge-table">
  <thead><tr><th>Challenge</th><th>Solution / Mitigation</th></tr></thead>
  <tbody>
    <tr>
      <td>Inconsistent user adoption of new features.</td>
      <td>Conducted targeted one-on-one follow-up training and prepared simplified user guides (cheat sheets) for key ERP modules.</td>
    </tr>
    <tr>
      <td>Identifying the root cause of legacy code errors.</td>
      <td>Used debugging tools and collaborated with the senior developer to trace errors back to their source and apply permanent fixes.</td>
    </tr>
    <tr>
      <td>Balancing new development with daily support requests.</td>
      <td>Prioritized tasks by business impact, documented recurring issues, and scheduled focused development blocks during lower-traffic periods.</td>
    </tr>
    <tr>
      <td>Differences between local and production environments.</td>
      <td>Aligned development and testing practices more closely with the live server configuration to reduce deployment discrepancies.</td>
    </tr>
  </tbody>
</table>

<h2>5.0 Upcoming Plan and Recommendations</h2>

<h3>5.1 Immediate Priorities (September 2026)</h3>
<ul>
  <li><strong>Desktop Application Rollout and Stabilization:</strong> Finalize deployment, monitor stability, and address immediate client-side issues.</li>
  <li><strong>Performance Module Development:</strong> Move from design to development, including backend data capture and processing.</li>
  <li><strong>Web Scraper Optimization:</strong> Improve scraping efficiency and schedule automated scraping during off-peak hours.</li>
</ul>

<h3>5.2 Allocation of Funds to Support the IT Department</h3>
<p>
  To sustain and accelerate system development, the IT department requires dedicated budget allocation for application coding,
  API services, and related infrastructure. Recommended funding areas include:
</p>
<ul>
  <li><strong>Application Development:</strong> Resources for ongoing ERP enhancements, desktop application maintenance, new module development, and developer tools/licenses required for coding and testing.</li>
  <li><strong>API Services and Integrations:</strong> Funding for third-party API subscriptions, cloud/hosting services, integration platforms, and secure connectivity needed to support customer data collection, notifications, and external system links.</li>
  <li><strong>IT Infrastructure and Support:</strong> Backup storage, staging/production server capacity, security tools, and technical resources to keep systems stable as usage grows across departments.</li>
</ul>
<p>
  Allocating funds to these areas will enable the IT team to deliver improvements faster, reduce downtime, and support the company's
  digital operations with greater reliability.
</p>
HTML;

$headerHtml = '
<div style="width:100%; border-bottom:1px solid #e2e8f0; padding-bottom:6px; margin-bottom:4px;">
  <table width="100%" cellpadding="0" cellspacing="0" style="font-family: dejavusans, Arial, sans-serif; font-size:8pt; color:#64748b;">
    <tr>
      <td style="width:65%; vertical-align:middle;">IT Department Progress Report - System Development and IT Operations</td>
      <td style="width:35%; text-align:right; vertical-align:middle;">
        <img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" style="height:42px; width:auto;" alt="Ultimate General Trading" />
      </td>
    </tr>
  </table>
</div>';

$footerHtml = '
<div style="font-family: dejavusans, Arial, sans-serif; font-size:8pt; color:#94a3b8; border-top:1px solid #e2e8f0; padding-top:4px; text-align:center;">
  Ultimate General Trading | Confidential - Page {PAGENO} of {nbpg}
</div>';

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 18,
        'margin_right' => 18,
        'margin_top' => 28,
        'margin_bottom' => 18,
        'default_font' => 'dejavusans',
    ]);

    $mpdf->SetTitle('IT Department Progress Report - Ultimate General Trading');
    $mpdf->SetAuthor('Ultimate General Trading');
    $mpdf->SetSubject('IT Department Progress Report');
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);
    $mpdf->WriteHTML($html);
    $mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);

    $simplePdf = __DIR__ . '/IT_Department_Report.pdf';
    if (!copy($outputPath, $simplePdf)) {
        throw new RuntimeException('Could not copy PDF to ' . $simplePdf);
    }

    echo "PDF generated: {$outputPath}\n";
    echo "PDF copy: {$simplePdf}\n";
    echo "HTML report: " . __DIR__ . "/IT_Department_Report.html\n";
    echo 'Size: ' . number_format(filesize($outputPath)) . " bytes\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'PDF generation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
