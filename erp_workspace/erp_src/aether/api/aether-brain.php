<?php
/**
 * Aether Brain — Self-Sufficient NLP Engine
 * Zero external AI API dependency. Pure PHP.
 *
 * Architecture:
 *  1. Intent Detection   — pattern matching + keyword scoring
 *  2. Entity Extraction  — named entities, numbers, dates, emails, phones
 *  3. Context Manager    — multi-turn memory + slot filling
 *  4. Tool Dispatcher    — maps intent + entities → ERP tool calls
 *  5. Response Generator — structured, natural language replies
 */

class AetherBrain
{
    private PDO $db;
    private array $user;
    private array $history;
    private array $context;   // slot-filling state

    // ── Intent registry ──────────────────────────────────────────────────────
    // Each intent has: keywords (scored), patterns (regex, high-priority),
    // required_slots (for multi-turn confirmation), and a handler method.
    private const INTENTS = [

        // ── Greetings / small talk ──────────────────────────────────────────
        'greeting' => [
            'patterns' => ['/^(hi|hello|hey|good\s*(morning|afternoon|evening|night)|namaste|namaskar|hola|howdy)\b/i'],
            'keywords' => ['hi'=>5,'hello'=>5,'hey'=>5,'morning'=>4,'afternoon'=>4,'evening'=>4,'night'=>4,'namaste'=>5,'namaskar'=>5],
            'handler'  => 'handleGreeting',
        ],
        'thanks' => [
            'patterns' => ['/^(thank(s| you)|dhanyawad|shukriya|great|awesome|perfect|wonderful|excellent)\b/i'],
            'keywords' => ['thanks'=>5,'thank'=>4,'dhanyawad'=>5,'great'=>3,'awesome'=>3,'perfect'=>3],
            'handler'  => 'handleThanks',
        ],
        'help' => [
            'patterns' => ['/\bwhat can you (do|help)\b/i', '/\bhelp\b/i', '/\bcommands?\b/i', '/\bcapabilit/i'],
            'keywords' => ['help'=>4,'assist'=>3,'commands'=>4,'capable'=>3,'features'=>3,'abilities'=>3,'can you'=>3,'what do you'=>3],
            'handler'  => 'handleHelp',
        ],

        // ── Dashboard ───────────────────────────────────────────────────────
        'dashboard' => [
            'patterns' => ['/\b(dashboard|overview|summary|stats|statistics|snapshot)\b/i'],
            'keywords' => ['dashboard'=>5,'overview'=>5,'summary'=>4,'stats'=>5,'statistics'=>4,'snapshot'=>4,'quick look'=>4,'status'=>3],
            'handler'  => 'handleDashboard',
        ],

        // ── Donations ───────────────────────────────────────────────────────
        'list_donations' => [
            'patterns' => ['/\b(list|show|get|fetch|see|view|recent|all)\b.*\bdonation/i', '/\bdonations?\b.*\b(list|show|get|recent)/i'],
            'keywords' => ['donations'=>5,'donation'=>4,'contributions'=>4,'receipts'=>3,'received'=>3,'list'=>2,'show'=>2,'recent'=>3],
            'handler'  => 'handleListDonations',
        ],
        'record_donation' => [
            'patterns' => ['/\b(record|add|new|create|log|register|save|enter)\b.*\bdonation/i', '/\bdonation\b.*\b(record|add|new|create|receive|got|received)\b/i', '/\b(received|got)\b.*\b(donation|donated|money|amount|funds)\b/i'],
            'keywords' => ['record'=>3,'add'=>2,'new donation'=>5,'received donation'=>5,'register donation'=>5,'log donation'=>5,'donated'=>4,'contribute'=>4],
            'handler'  => 'handleRecordDonation',
        ],

        // ── Donors ──────────────────────────────────────────────────────────
        'list_donors' => [
            'patterns' => ['/\b(list|show|all|get|find|search|view)\b.*\bdonors?\b/i', '/\bdonors?\b.*\b(list|all|show)\b/i'],
            'keywords' => ['donors'=>5,'donor list'=>6,'all donors'=>6,'show donors'=>6,'find donor'=>5],
            'handler'  => 'handleListDonors',
        ],
        'donor_details' => [
            'patterns' => ['/\b(details?|info(rmation)?|profile|about|tell me about)\b.*\bdonor\b/i', '/\bdonor\b.*\b(details?|info|profile)\b/i'],
            'keywords' => ['donor details'=>6,'donor info'=>6,'donor profile'=>6,'about donor'=>5],
            'handler'  => 'handleDonorDetails',
        ],
        'create_donor' => [
            'patterns' => ['/\b(add|create|register|new|onboard)\b.*\bdonor\b/i', '/\bdonor\b.*\b(add|create|register|new)\b/i'],
            'keywords' => ['add donor'=>6,'new donor'=>6,'create donor'=>6,'register donor'=>6,'onboard donor'=>5],
            'handler'  => 'handleCreateDonor',
        ],

        // ── Expenses ────────────────────────────────────────────────────────
        'list_expenses' => [
            'patterns' => ['/\b(list|show|get|view|all|recent)\b.*\bexpenses?\b/i', '/\bexpenses?\b.*\b(list|show|this month|last month)\b/i'],
            'keywords' => ['expenses'=>5,'expense list'=>6,'show expenses'=>6,'all expenses'=>6,'spending'=>4,'expenditure'=>4],
            'handler'  => 'handleListExpenses',
        ],
        'create_expense' => [
            'patterns' => ['/\b(add|record|log|create|new|enter)\b.*\bexpense\b/i', '/\bexpense\b.*\b(add|record|log|new)\b/i'],
            'keywords' => ['add expense'=>6,'new expense'=>6,'record expense'=>6,'log expense'=>6,'create expense'=>6],
            'handler'  => 'handleCreateExpense',
        ],

        // ── Employees ───────────────────────────────────────────────────────
        'list_employees' => [
            'patterns' => ['/\b(list|show|all|get|view|find)\b.*\bemployees?\b/i', '/\b(staff|employees?|team|members?)\b.*\b(list|all|show)\b/i'],
            'keywords' => ['employees'=>5,'staff list'=>6,'team members'=>5,'all staff'=>6,'show employees'=>6,'workforce'=>4],
            'handler'  => 'handleListEmployees',
        ],
        'employee_details' => [
            'patterns' => ['/\b(details?|info(rmation)?|profile|about|tell me about)\b.*\bemployee\b/i'],
            'keywords' => ['employee details'=>6,'employee info'=>6,'employee profile'=>6,'staff info'=>5],
            'handler'  => 'handleEmployeeDetails',
        ],
        'update_salary' => [
            'patterns' => ['/\b(update|change|set|modify|revise|increase|decrease|raise|hike)\b.*\bsalary\b/i', '/\bsalary\b.*\b(update|change|set|modify|raise|hike|increment)\b/i', '/\bincrement\b.*\bsalary\b/i'],
            'keywords' => ['salary update'=>6,'update salary'=>6,'change salary'=>6,'salary hike'=>6,'salary increment'=>6,'raise salary'=>5,'pay rise'=>5],
            'handler'  => 'handleUpdateSalary',
        ],

        // ── Payslips & Receipts ─────────────────────────────────────────────
        'generate_payslip' => [
            'patterns' => ['/\b(generate|create|make|produce|issue|print)\b.*\bpayslip\b/i', '/\bpayslip\b.*\b(generate|create|for)\b/i'],
            'keywords' => ['payslip'=>6,'pay slip'=>6,'salary slip'=>6,'generate payslip'=>7,'create payslip'=>7],
            'handler'  => 'handleGeneratePayslip',
        ],
        'generate_receipt' => [
            'patterns' => ['/\b(generate|create|make|produce|issue|print)\b.*\breceipt\b/i', '/\breceipt\b.*\b(generate|create|for)\b/i', '/\bdonation receipt\b/i'],
            'keywords' => ['receipt'=>5,'donation receipt'=>7,'generate receipt'=>7,'create receipt'=>7,'80g'=>6],
            'handler'  => 'handleGenerateReceipt',
        ],

        // ── Messaging ───────────────────────────────────────────────────────
        'send_email' => [
            'patterns' => ['/\bsend\b.*\bemail\b/i', '/\bemail\b.*\bsend\b/i', '/\bmail\b.*\bto\b/i'],
            'keywords' => ['send email'=>7,'email to'=>6,'mail to'=>5,'email reminder'=>6,'send mail'=>6],
            'handler'  => 'handleSendEmail',
        ],
        'send_sms' => [
            'patterns' => ['/\bsend\b.*\bsms\b/i', '/\bsms\b.*\bsend\b/i', '/\btext message\b/i', '/\bsend\b.*\bmessage\b.*\b(phone|mobile|number)\b/i'],
            'keywords' => ['send sms'=>7,'sms to'=>6,'text to'=>5,'sms reminder'=>6,'send text'=>5],
            'handler'  => 'handleSendSms',
        ],
        'send_whatsapp' => [
            'patterns' => ['/\bsend\b.*\bwhatsapp\b/i', '/\bwhatsapp\b.*\b(send|message|msg)\b/i', '/\bwa\b.*\bsend\b/i'],
            'keywords' => ['whatsapp'=>6,'send whatsapp'=>7,'wa message'=>6,'whatsapp message'=>7],
            'handler'  => 'handleSendWhatsapp',
        ],

        // ── Clear / Fallback ────────────────────────────────────────────────
        'clear_chat' => [
            'patterns' => ['/\b(clear|reset|wipe|delete|remove)\b.*\b(chat|history|conversation|memory)\b/i'],
            'keywords' => ['clear chat'=>7,'clear history'=>7,'reset chat'=>7,'new conversation'=>5,'start over'=>5],
            'handler'  => 'handleClearChat',
        ],
        'unknown' => [
            'patterns' => [],
            'keywords' => [],
            'handler'  => 'handleUnknown',
        ],
    ];

    // ── Number words → digits ────────────────────────────────────────────────
    private const NUMBER_WORDS = [
        'zero'=>0,'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,
        'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,
        'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'twenty'=>20,'thirty'=>30,
        'forty'=>40,'fifty'=>50,'hundred'=>100,'thousand'=>1000,'lakh'=>100000,
        'crore'=>10000000,
        // Hindi number words
        'ek'=>1,'do'=>2,'teen'=>3,'char'=>4,'paanch'=>5,'chhe'=>6,'saat'=>7,
        'aath'=>8,'nau'=>9,'das'=>10,'sau'=>100,'hazaar'=>1000,
    ];

    public function __construct(PDO $db, array $user, array $history = []) {
        $this->db      = $db;
        $this->user    = $user;
        $this->history = $history;
        $this->context = [];
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PUBLIC ENTRY POINT
    // ════════════════════════════════════════════════════════════════════════

    public function respond(string $message, string $conversationId): string {
        $message = trim($message);

        // 1. Detect intent
        $intent  = $this->detectIntent($message);

        // 2. Extract entities
        $entities = $this->extractEntities($message);

        // 3. Dispatch to handler
        $handler = self::INTENTS[$intent]['handler'];
        return $this->$handler($message, $entities, $conversationId);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  INTENT DETECTION
    // ════════════════════════════════════════════════════════════════════════

    private function detectIntent(string $msg): string {
        $lower = strtolower($msg);
        $best  = 'unknown';
        $bestScore = 0;

        foreach (self::INTENTS as $intent => $cfg) {
            if ($intent === 'unknown') continue;

            $score = 0;

            // Pattern matching (high weight)
            foreach ($cfg['patterns'] as $pattern) {
                if (preg_match($pattern, $msg)) {
                    $score += 20;
                    break; // one pattern match is enough
                }
            }

            // Keyword scoring
            foreach ($cfg['keywords'] as $kw => $weight) {
                if (str_contains($lower, $kw)) {
                    $score += $weight;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $intent;
            }
        }

        return $bestScore > 0 ? $best : 'unknown';
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ENTITY EXTRACTION
    // ════════════════════════════════════════════════════════════════════════

    private function extractEntities(string $msg): array {
        $entities = [];

        // ── Amount / money ──────────────────────────────────────────────────
        // Matches: ₹5000, Rs.5000, Rs 5000, 5000 rupees, 5,000, 5 lakh, etc.
        if (preg_match('/(?:₹|rs\.?\s*|rupees?\s*)([0-9,\.]+(?:\s*(?:lakh|crore|thousand|k|l|cr))?)/i', $msg, $m)) {
            $entities['amount'] = $this->parseAmount($m[1]);
        } elseif (preg_match('/\b([0-9,\.]+)\s*(?:rupees?|rs\.?|inr|₹)\b/i', $msg, $m)) {
            $entities['amount'] = $this->parseAmount($m[1]);
        } elseif (preg_match('/\b(\d{3,}(?:,\d+)*(?:\.\d+)?)\b/', $msg, $m)) {
            $entities['amount'] = $this->parseAmount($m[1]);
        }

        // ── Email ───────────────────────────────────────────────────────────
        if (preg_match('/\b[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}\b/', $msg, $m)) {
            $entities['email'] = $m[0];
        }

        // ── Phone number ────────────────────────────────────────────────────
        if (preg_match('/\b(?:\+91[\s\-]?)?([6-9]\d{9})\b/', $msg, $m)) {
            $entities['phone'] = preg_replace('/\D/', '', $m[0]);
        }

        // ── Month / Year ────────────────────────────────────────────────────
        $months = ['january'=>'01','february'=>'02','march'=>'03','april'=>'04',
                   'may'=>'05','june'=>'06','july'=>'07','august'=>'08',
                   'september'=>'09','october'=>'10','november'=>'11','december'=>'12',
                   'jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','jun'=>'06',
                   'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
        foreach ($months as $name => $num) {
            if (stripos($msg, $name) !== false) {
                $entities['month_name'] = ucfirst($name);
                $entities['month_num']  = $num;
                break;
            }
        }

        if (preg_match('/\b(20\d{2})\b/', $msg, $m)) {
            $entities['year'] = $m[1];
        } else {
            $entities['year'] = date('Y');
        }

        // ── Payment method ──────────────────────────────────────────────────
        $paymentMethods = ['upi','neft','rtgs','imps','cash','cheque','check','bank transfer','online','card','credit card','debit card','demand draft','dd'];
        foreach ($paymentMethods as $pm) {
            if (stripos($msg, $pm) !== false) {
                $entities['payment_method'] = strtoupper($pm);
                break;
            }
        }

        // ── Expense category ────────────────────────────────────────────────
        $categories = ['salary','rent','utilities','travel','food','office','maintenance','equipment','medical','education','event','program','marketing','communication','transport','miscellaneous'];
        foreach ($categories as $cat) {
            if (stripos($msg, $cat) !== false) {
                $entities['expense_category'] = ucfirst($cat);
                break;
            }
        }

        // ── Quoted name or "for [Name]" ─────────────────────────────────────
        if (preg_match('/(?:for|of|named?|called)\s+"?([A-Z][a-zA-Z\s]{1,40})"?/i', $msg, $m)) {
            $entities['person_name'] = trim($m[1]);
        } elseif (preg_match('/"([^"]{2,50})"/', $msg, $m)) {
            $entities['person_name'] = trim($m[1]);
        }

        // ── Employee/donor ID ───────────────────────────────────────────────
        if (preg_match('/\b(?:id|#|no\.?)\s*(\d+)/i', $msg, $m)) {
            $entities['id'] = (int)$m[1];
        }

        // ── Transaction ID ──────────────────────────────────────────────────
        if (preg_match('/\b(?:txn|transaction|ref|utr|reference)[\s:#\-]*([A-Z0-9]{8,24})\b/i', $msg, $m)) {
            $entities['transaction_id'] = strtoupper($m[1]);
        }

        // ── Limit (how many) ────────────────────────────────────────────────
        if (preg_match('/\b(?:last|top|recent|first)?\s*(\d+)\b.*\b(?:donations?|donors?|expenses?|employees?|staff)\b/i', $msg, $m)) {
            $entities['limit'] = (int)$m[1];
        }

        // ── Date ────────────────────────────────────────────────────────────
        if (preg_match('/\b(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})\b/', $msg, $m)) {
            $entities['date'] = $this->normalizeDate($m[1]);
        } elseif (preg_match('/\btoday\b/i', $msg)) {
            $entities['date'] = date('Y-m-d');
        } elseif (preg_match('/\byesterday\b/i', $msg)) {
            $entities['date'] = date('Y-m-d', strtotime('-1 day'));
        }

        // ── Free-text title (for expenses) ──────────────────────────────────
        if (preg_match('/(?:expense|paid|spent|cost|bill)\s+(?:for|on|of)?\s+"?([a-zA-Z][a-zA-Z\s]{2,50})"?/i', $msg, $m)) {
            $entities['expense_title'] = trim($m[1]);
        }

        // ── Subject (for email) ─────────────────────────────────────────────
        if (preg_match('/\bsubject[\s:]+["\'"]?(.{5,80})["\'"]?/i', $msg, $m)) {
            $entities['email_subject'] = trim($m[1]);
        }

        return $entities;
    }

    // ── Parse amount string → float ──────────────────────────────────────────
    private function parseAmount(string $raw): float {
        $raw = strtolower(trim($raw));
        $raw = str_replace(',', '', $raw);

        $multiplier = 1;
        if (str_contains($raw, 'crore') || str_contains($raw, 'cr')) $multiplier = 10000000;
        elseif (str_contains($raw, 'lakh') || str_ends_with(trim($raw), 'l'))  $multiplier = 100000;
        elseif (str_contains($raw, 'thousand') || str_ends_with(trim($raw), 'k')) $multiplier = 1000;

        $num = (float)preg_replace('/[^0-9.]/', '', $raw);
        return $num * $multiplier;
    }

    // ── Normalize date string ─────────────────────────────────────────────────
    private function normalizeDate(string $raw): string {
        $ts = strtotime(str_replace('/', '-', $raw));
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  HANDLERS — one per intent
    // ════════════════════════════════════════════════════════════════════════

    private function handleGreeting(string $msg, array $e, string $cid): string {
        $name = $this->user['full_name'] ?? $this->user['name'] ?? 'there';
        $hour = (int)date('H');
        $tod  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $greetings = [
            "{$tod}, **{$name}**! 👋 I'm Aether, your ERP assistant. How can I help you today?",
            "Hello **{$name}**! Great to see you. What would you like to do today?",
            "Hi **{$name}**! I'm ready to help. Ask me about donations, expenses, employees, or anything ERP-related!",
        ];
        return $greetings[array_rand($greetings)];
    }

    private function handleThanks(string $msg, array $e, string $cid): string {
        $replies = [
            "You're welcome! Is there anything else I can help you with?",
            "Happy to help! Let me know if you need anything else.",
            "Anytime! Feel free to ask if you need more assistance.",
        ];
        return $replies[array_rand($replies)];
    }

    private function handleHelp(string $msg, array $e, string $cid): string {
        return <<<MD
Here's what I can do for you:

**📊 Dashboard & Reports**
- Show dashboard overview / stats
- List donations, expenses, donors, employees

**🤝 Donor Management**
- Search or list all donors
- View donor details (by name or ID)
- Add a new donor

**💰 Donations**
- Show recent or filtered donations
- Record a new donation

**💸 Expenses**
- List expenses (by category or month)
- Add a new expense

**👥 Employee Management**
- List all staff / employees
- View employee details
- Update employee salary

**📄 Documents**
- Generate payslip (PDF)
- Generate donation receipt (PDF)

**📬 Communication**
- Send Email
- Send SMS (via Fast2SMS)
- Send WhatsApp message

Just ask in plain English — for example:
_"Show me the last 5 donations"_ or _"Record a ₹10,000 donation from Ramesh"_
MD;
    }

    // ── Dashboard ────────────────────────────────────────────────────────────

    private function handleDashboard(string $msg, array $e, string $cid): string {
        try {
            $totalDonations  = $this->db->query("SELECT COALESCE(SUM(amount),0) FROM donations")->fetchColumn();
            $totalDonors     = $this->db->query("SELECT COUNT(*) FROM donors")->fetchColumn();
            $monthExpenses   = $this->db->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
            $staffCount      = $this->db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
            $recent          = $this->db->query("SELECT d.full_name AS donor, dn.amount, dn.created_at FROM donations dn LEFT JOIN donors d ON d.id=dn.donor_id ORDER BY dn.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

            $out  = "## 📊 ERP Dashboard — " . date('d M Y') . "\n\n";
            $out .= "| Metric | Value |\n|---|---|\n";
            $out .= "| Total Donations Received | ₹" . number_format((float)$totalDonations, 2) . " |\n";
            $out .= "| Total Donors | " . number_format((int)$totalDonors) . " |\n";
            $out .= "| This Month's Expenses | ₹" . number_format((float)$monthExpenses, 2) . " |\n";
            $out .= "| Active Staff | " . (int)$staffCount . " |\n\n";

            if ($recent) {
                $out .= "**Recent Donations:**\n\n";
                $out .= "| Donor | Amount | Date |\n|---|---|---|\n";
                foreach ($recent as $r) {
                    $out .= "| " . htmlspecialchars($r['donor'] ?? 'N/A') . " | ₹" . number_format((float)$r['amount'], 2) . " | " . date('d M Y', strtotime($r['created_at'])) . " |\n";
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return "❌ Could not fetch dashboard data: " . $e->getMessage();
        }
    }

    // ── Donations ────────────────────────────────────────────────────────────

    private function handleListDonations(string $msg, array $e, string $cid): string {
        $limit = min((int)($e['limit'] ?? 10), 50);
        try {
            $stmt = $this->db->prepare(
                "SELECT dn.id, d.full_name AS donor, dn.amount, dn.payment_method, dn.status, dn.created_at
                 FROM donations dn LEFT JOIN donors d ON d.id=dn.donor_id
                 ORDER BY dn.id DESC LIMIT ?"
            );
            $stmt->execute([$limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) return "No donations found in the database yet.";

            $out = "## 💰 Recent Donations (last {$limit})\n\n";
            $out .= "| # | Donor | Amount | Method | Status | Date |\n|---|---|---|---|---|---|\n";
            foreach ($rows as $r) {
                $out .= "| #{$r['id']} | " . htmlspecialchars($r['donor'] ?? 'N/A') . " | ₹" . number_format((float)$r['amount'], 2) . " | " . ($r['payment_method'] ?? 'N/A') . " | " . ($r['status'] ?? 'N/A') . " | " . date('d M Y', strtotime($r['created_at'])) . " |\n";
            }
            return $out;
        } catch (\Throwable $ex) {
            return "❌ Error: " . $ex->getMessage();
        }
    }

    private function handleRecordDonation(string $msg, array $e, string $cid): string {
        // Need: donor_id (or name), amount, payment_method
        $amount        = $e['amount'] ?? 0;
        $paymentMethod = $e['payment_method'] ?? null;
        $personName    = $e['person_name'] ?? null;
        $donorId       = $e['id'] ?? null;

        // Try to find donor by name if no ID
        if (!$donorId && $personName) {
            $donorId = $this->findDonorIdByName($personName);
        }

        // Build missing slots message
        $missing = [];
        if (!$donorId && !$personName) $missing[] = "**donor name or ID**";
        if ($amount <= 0)              $missing[] = "**amount** (e.g. ₹5,000)";
        if (!$paymentMethod)           $missing[] = "**payment method** (UPI, Cash, NEFT, etc.)";

        if ($missing) {
            return "To record a donation, I need the following information:\n- " . implode("\n- ", $missing) . "\n\nPlease provide these details and I'll record it right away!";
        }

        // If name given but no DB match, offer to create
        if (!$donorId) {
            return "I couldn't find a donor named **{$personName}** in the database. Would you like me to:\n1. Create a new donor record for them, then record the donation\n2. Search by a different name\n\nPlease clarify!";
        }

        // Get donor name for confirmation
        $donorRow = $this->db->prepare("SELECT full_name FROM donors WHERE id = ?");
        $donorRow->execute([$donorId]);
        $donor = $donorRow->fetchColumn() ?: "Donor #{$donorId}";

        // Confirm before inserting
        return "⚠️ **Please confirm before I proceed:**\n\n" .
               "| Field | Value |\n|---|---|\n" .
               "| Donor | {$donor} |\n" .
               "| Amount | ₹" . number_format($amount, 2) . " |\n" .
               "| Payment Method | {$paymentMethod} |\n" .
               "| Transaction ID | " . ($e['transaction_id'] ?? 'N/A') . " |\n\n" .
               "Reply **yes** or **confirm** to record this donation, or **no** to cancel.\n\n" .
               "_[Pending: record_donation donor_id={$donorId} amount={$amount} method={$paymentMethod} txn=" . ($e['transaction_id'] ?? '') . "]_";
    }

    // ── Donors ───────────────────────────────────────────────────────────────

    private function handleListDonors(string $msg, array $e, string $cid): string {
        $limit  = min((int)($e['limit'] ?? 20), 50);
        $search = $e['person_name'] ?? '';
        try {
            if ($search) {
                $w    = "%{$search}%";
                $stmt = $this->db->prepare("SELECT id, full_name, email, phone, total_donated FROM donors WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY full_name ASC LIMIT ?");
                $stmt->execute([$w, $w, $w, $limit]);
            } else {
                $stmt = $this->db->prepare("SELECT id, full_name, email, phone, total_donated FROM donors ORDER BY id DESC LIMIT ?");
                $stmt->execute([$limit]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) return $search ? "No donors found matching **{$search}**." : "No donors in the database yet.";

            $out  = "## 🤝 Donors" . ($search ? " — Search: {$search}" : " (last {$limit})") . "\n\n";
            $out .= "| ID | Name | Email | Phone | Total Donated |\n|---|---|---|---|---|\n";
            foreach ($rows as $r) {
                $out .= "| #{$r['id']} | " . htmlspecialchars($r['full_name']) . " | " . ($r['email'] ?: '—') . " | " . ($r['phone'] ?: '—') . " | ₹" . number_format((float)$r['total_donated'], 2) . " |\n";
            }
            return $out;
        } catch (\Throwable $ex) {
            return "❌ Error: " . $ex->getMessage();
        }
    }

    private function handleDonorDetails(string $msg, array $e, string $cid): string {
        $id     = $e['id'] ?? 0;
        $search = $e['person_name'] ?? '';

        if (!$id && !$search) {
            return "Please tell me which donor you want details for — by **name** or **ID number**.";
        }

        try {
            if ($id > 0) {
                $stmt = $this->db->prepare("SELECT * FROM donors WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $w    = "%{$search}%";
                $stmt = $this->db->prepare("SELECT * FROM donors WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? LIMIT 1");
                $stmt->execute([$w, $w, $w]);
            }
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return "No donor found" . ($search ? " matching **{$search}**" : " with ID #{$id}") . ".";

            // Fetch donation history
            $hist = $this->db->prepare("SELECT amount, payment_method, status, created_at FROM donations WHERE donor_id = ? ORDER BY id DESC LIMIT 5");
            $hist->execute([$r['id']]);
            $donations = $hist->fetchAll(PDO::FETCH_ASSOC);

            $out  = "## 🤝 Donor Profile\n\n";
            $out .= "| Field | Value |\n|---|---|\n";
            $out .= "| **ID** | #{$r['id']} |\n";
            $out .= "| **Name** | " . htmlspecialchars($r['full_name']) . " |\n";
            $out .= "| **Email** | " . ($r['email'] ?: '—') . " |\n";
            $out .= "| **Phone** | " . ($r['phone'] ?: '—') . " |\n";
            $out .= "| **Address** | " . ($r['address'] ?: '—') . " |\n";
            $out .= "| **Total Donated** | ₹" . number_format((float)($r['total_donated'] ?? 0), 2) . " |\n";
            $out .= "| **Member Since** | " . date('d M Y', strtotime($r['created_at'])) . " |\n";

            if ($donations) {
                $out .= "\n**Donation History (last 5):**\n\n| Amount | Method | Status | Date |\n|---|---|---|---|\n";
                foreach ($donations as $d) {
                    $out .= "| ₹" . number_format((float)$d['amount'], 2) . " | " . ($d['payment_method'] ?? 'N/A') . " | " . ($d['status'] ?? 'N/A') . " | " . date('d M Y', strtotime($d['created_at'])) . " |\n";
                }
            }
            return $out;
        } catch (\Throwable $ex) {
            return "❌ Error: " . $ex->getMessage();
        }
    }

    private function handleCreateDonor(string $msg, array $e, string $cid): string {
        $name  = $e['person_name'] ?? '';
        $email = $e['email'] ?? '';
        $phone = $e['phone'] ?? '';

        if (!$name) {
            return "To add a new donor, I need at least their **full name**. What is the donor's name?";
        }

        // Check duplicate
        $chk = $this->db->prepare("SELECT id FROM donors WHERE full_name LIKE ? LIMIT 1");
        $chk->execute(["%{$name}%"]);
        if ($existing = $chk->fetchColumn()) {
            return "⚠️ A donor with a similar name already exists (ID #{$existing}). Did you mean them, or should I still create a new record for **{$name}**? (reply **yes** to create)";
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO donors (full_name, email, phone, created_at) VALUES (?,?,?,NOW())");
            $stmt->execute([$name, $email, $phone]);
            $newId = $this->db->lastInsertId();
            return "✅ Donor **{$name}** has been added successfully!\n\n| Field | Value |\n|---|---|\n| ID | #{$newId} |\n| Name | {$name} |\n| Email | " . ($email ?: '—') . " |\n| Phone | " . ($phone ?: '—') . " |";
        } catch (\Throwable $ex) {
            return "❌ Failed to create donor: " . $ex->getMessage();
        }
    }

    // ── Expenses ─────────────────────────────────────────────────────────────

    private function handleListExpenses(string $msg, array $e, string $cid): string {
        $limit    = min((int)($e['limit'] ?? 10), 50);
        $category = $e['expense_category'] ?? '';
        $month    = '';
        if (!empty($e['month_num'])) {
            $month = ($e['year'] ?? date('Y')) . '-' . $e['month_num'];
        }

        try {
            $sql    = "SELECT id, title, amount, category, date FROM expenses WHERE 1=1";
            $params = [];
            if ($category) { $sql .= " AND category = ?";               $params[] = $category; }
            if ($month)    { $sql .= " AND DATE_FORMAT(date,'%Y-%m')=?"; $params[] = $month; }
            $sql   .= " ORDER BY id DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) return "No expenses found" . ($category ? " in category **{$category}**" : "") . ($month ? " for **{$month}**" : "") . ".";

            $total = array_sum(array_column($rows, 'amount'));
            $out   = "## 💸 Expenses\n\n";
            $out  .= "| # | Title | Amount | Category | Date |\n|---|---|---|---|---|\n";
            foreach ($rows as $r) {
                $out .= "| #{$r['id']} | " . htmlspecialchars($r['title']) . " | ₹" . number_format((float)$r['amount'], 2) . " | " . ($r['category'] ?: 'N/A') . " | " . date('d M Y', strtotime($r['date'])) . " |\n";
            }
            $out .= "\n**Total: ₹" . number_format($total, 2) . "**";
            return $out;
        } catch (\Throwable $ex) {
            return "❌ Error: " . $ex->getMessage();
        }
    }

    private function handleCreateExpense(string $msg, array $e, string $cid): string {
        $title    = $e['expense_title'] ?? $e['person_name'] ?? '';
        $amount   = $e['amount'] ?? 0;
        $category = $e['expense_category'] ?? '';
        $date     = $e['date'] ?? date('Y-m-d');

        $missing = [];
        if (!$title)      $missing[] = "**expense title** (e.g. 'Office supplies', 'Travel to Delhi')";
        if ($amount <= 0) $missing[] = "**amount** (e.g. ₹2,500)";
        if (!$category)   $missing[] = "**category** (e.g. Travel, Salary, Utilities, Office, Food)";

        if ($missing) {
            return "To record an expense, I need:\n- " . implode("\n- ", $missing) . "\n\nPlease provide these details!";
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO expenses (title, amount, category, date, created_at) VALUES (?,?,?,?,NOW())");
            $stmt->execute([$title, $amount, $category, $date]);
            return "✅ Expense recorded successfully!\n\n| Field | Value |\n|---|---|\n| Title | {$title} |\n| Amount | ₹" . number_format($amount, 2) . " |\n| Category | {$category} |\n| Date | " . date('d M Y', strtotime($date)) . " |";
        } catch (\Throwable $ex) {
            return "❌ Failed to record expense: " . $ex->getMessage();
        }
    }

    // ── Employees ────────────────────────────────────────────────────────────

    private function handleListEmployees(string $msg, array $e, string $cid): string {
        $limit  = min((int)($e['limit'] ?? 20), 50);
        $search = $e['person_name'] ?? '';
        try {
            if ($search) {
                $w    = "%{$search}%";
                $stmt = $this->db->prepare("SELECT id, full_name, designation, department, basic_salary, status FROM employees WHERE full_name LIKE ? OR designation LIKE ? OR department LIKE ? ORDER BY full_name ASC LIMIT ?");
                $stmt->execute([$w, $w, $w, $limit]);
            } else {
                $stmt = $this->db->prepare("SELECT id, full_name, designation, department, basic_salary, status FROM employees ORDER BY full_name ASC LIMIT ?");
                $stmt->execute([$limit]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) return $search ? "No employees found matching **{$search}**." : "No employees in the database.";

            $out  = "## 👥 Employees" . ($search ? " — Search: {$search}" : "") . "\n\n";
            $out .= "| ID | Name | Designation | Department | Salary | Status |\n|---|---|---|---|---|---|\n";
            foreach ($rows as $r) {
                $out .= "| #{$r['id']} | " . htmlspecialchars($r['full_name']) . " | " . ($r['designation'] ?: '—') . " | " . ($r['department'] ?: '—') . " | ₹" . number_format((float)$r['basic_salary'], 2) . " | " . ($r['status'] ?? 'N/A') . " |\n";
            }
            return $out;
        } catch (\Throwable $ex) {
            return "❌ Error: " . $ex->getMessage();
        }
    }

    private function handleEmployeeDetails(string $msg, array $e, string $cid): string {
        $id     = $e['id'] ?? 0;
        $search = $e['person_name'] ?? '';

        if (!$id && !$search) {
            return "Please tell me which employee you want details for — by **name** or **ID number**.";
        }

        try {
            if ($id > 0) {
                $stmt = $this->db->prepare("SELECT * FROM employees WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $w    = "%{$search}%";
                $stmt = $this->db->prepare("SELECT * FROM employees WHERE full_name LIKE ? OR designation LIKE ? LIMIT 1");
                $stmt->execute([$w, $w]);
            }
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return "No employee found" . ($search ? " matching **{$search}**" : " with ID #{$id}") . ".";

            $out  = "## 👤 Employee Profile\n\n";
            $out .= "| Field | Value |\n|---|---|\n";
            foreach (['id'=>'ID','full_name'=>'Name','designation'=>'Designation','department'=>'Department','email'=>'Email','phone'=>'Phone','basic_salary'=>'Basic Salary','status'=>'Status','join_date'=>'Join Date'] as $col => $label) {
                if (!array_key_exists($col, $r)) continue;
                $val = $r[$col];
                if ($col === 'basic_salary') $val = '₹' . number_format((float)$val, 2);
                if ($col === 'id')           $val = "#{$val}";
                if (in_array($col, ['join_date','created_at']) && $val) $val = date('d M Y', strtotime($val));
                $out .= "| **{$label}** | " . htmlspecialchars((string)($val ?: '—')) . " |\n";
            }
            return $out;
        } catch (\Throwable $ex) {
            return "❌ Error: " . $ex->getMessage();
        }
    }

    private function handleUpdateSalary(string $msg, array $e, string $cid): string {
        $id          = $e['id'] ?? 0;
        $personName  = $e['person_name'] ?? '';
        $newSalary   = $e['amount'] ?? 0;

        // Find employee
        $empId = $id;
        if (!$empId && $personName) {
            $s = $this->db->prepare("SELECT id FROM employees WHERE full_name LIKE ? LIMIT 1");
            $s->execute(["%{$personName}%"]);
            $empId = (int)$s->fetchColumn();
        }

        if (!$empId) {
            return "Please tell me which employee's salary to update — by **name** or **employee ID**.";
        }
        if ($newSalary <= 0) {
            return "Please tell me the **new salary amount** for this employee.";
        }

        // Get current salary for confirmation
        $emp = $this->db->prepare("SELECT full_name, basic_salary FROM employees WHERE id = ?");
        $emp->execute([$empId]);
        $empRow = $emp->fetch(PDO::FETCH_ASSOC);

        if (!$empRow) return "❌ Employee ID #{$empId} not found.";

        return "⚠️ **Confirm Salary Update:**\n\n" .
               "| | Value |\n|---|---|\n" .
               "| Employee | {$empRow['full_name']} |\n" .
               "| Current Salary | ₹" . number_format((float)$empRow['basic_salary'], 2) . " |\n" .
               "| New Salary | ₹" . number_format($newSalary, 2) . " |\n\n" .
               "Reply **yes** to confirm, or **no** to cancel.\n\n" .
               "_[Pending: update_salary emp_id={$empId} salary={$newSalary}]_";
    }

    // ── PDF Documents ─────────────────────────────────────────────────────────

    private function handleGeneratePayslip(string $msg, array $e, string $cid): string {
        $personName = $e['person_name'] ?? '';
        $month      = $e['month_name'] ?? date('F');
        $year       = $e['year'] ?? date('Y');
        $salary     = $e['amount'] ?? 0;

        if (!$personName) {
            return "To generate a payslip, I need the **employee's name**. Who should the payslip be for?";
        }

        // Try to look up from DB
        if (!$salary) {
            $s = $this->db->prepare("SELECT basic_salary, designation FROM employees WHERE full_name LIKE ? LIMIT 1");
            $s->execute(["%{$personName}%"]);
            $emp = $s->fetch(PDO::FETCH_ASSOC);
            if ($emp) {
                $salary      = (float)$emp['basic_salary'];
                $designation = $emp['designation'] ?? 'N/A';
            }
        }

        if (!$salary) {
            return "I couldn't find **{$personName}** in the employees database, or no salary is set. Please provide the **basic salary amount** to generate the payslip.";
        }

        // Load helpers for PDF generation
        if (!function_exists('generateDocument')) {
            try { require_once __DIR__ . '/helpers.php'; } catch (\Throwable $ex) {}
        }
        if (!function_exists('generateDocument')) {
            return "❌ PDF library (mPDF) is not installed. Please run `composer require mpdf/mpdf` in the aether folder.";
        }

        $data = [
            'employee_name' => $personName,
            'employee_code' => 'N/A',
            'designation'   => $designation ?? 'N/A',
            'month'         => $month,
            'year'          => $year,
            'basic_salary'  => number_format($salary, 2),
            'generated_on'  => date('d M Y'),
        ];

        $link = generateDocument('payslip', $data);
        if (str_starts_with($link, '❌')) return $link;

        return "✅ **Payslip Generated!**\n\n| | |\n|---|---|\n| Employee | {$personName} |\n| Period | {$month} {$year} |\n| Basic Salary | ₹" . number_format($salary, 2) . " |\n\n📄 [Download Payslip]({$link})";
    }

    private function handleGenerateReceipt(string $msg, array $e, string $cid): string {
        $personName    = $e['person_name'] ?? '';
        $amount        = $e['amount'] ?? 0;
        $paymentMethod = $e['payment_method'] ?? 'N/A';
        $txnId         = $e['transaction_id'] ?? 'N/A';

        $missing = [];
        if (!$personName) $missing[] = "**donor name**";
        if ($amount <= 0) $missing[] = "**donation amount**";

        if ($missing) {
            return "To generate a receipt, I need the " . implode(" and ", $missing) . ". Please provide these details.";
        }

        if (!function_exists('generateDocument')) {
            try { require_once __DIR__ . '/helpers.php'; } catch (\Throwable $ex) {}
        }
        if (!function_exists('generateDocument')) {
            return "❌ PDF library (mPDF) is not installed. Please run `composer require mpdf/mpdf` in the aether folder.";
        }

        $data = [
            'donor_name'     => $personName,
            'amount'         => number_format($amount, 2),
            'payment_method' => $paymentMethod,
            'transaction_id' => $txnId,
            'program_name'   => 'General Fund',
            'receipt_date'   => date('d M Y'),
            'receipt_no'     => 'RCP-' . date('Ymd') . '-' . rand(100, 999),
        ];

        $link = generateDocument('receipt', $data);
        if (str_starts_with($link, '❌')) return $link;

        return "✅ **Donation Receipt Generated!**\n\n| | |\n|---|---|\n| Donor | {$personName} |\n| Amount | ₹" . number_format($amount, 2) . " |\n| Method | {$paymentMethod} |\n\n📄 [Download Receipt]({$link})";
    }

    // ── Messaging ─────────────────────────────────────────────────────────────

    private function handleSendEmail(string $msg, array $e, string $cid): string {
        $to      = $e['email'] ?? '';
        $subject = $e['email_subject'] ?? '';
        $body    = '';

        if (!$to)      return "To send an email, I need the **recipient's email address**. Who should I send it to?";
        if (!$subject) return "Got the recipient (**{$to}**). What should the **email subject** be?";

        if (!function_exists('sendEmail')) {
            try { require_once __DIR__ . '/helpers.php'; } catch (\Throwable $ex) {}
        }
        if (!function_exists('sendEmail')) return "❌ PHPMailer is not installed.";

        // Build a simple body from the message
        $body = "Dear recipient,\n\nThis is a message from Dhrub Foundation.\n\n" . strip_tags($msg) . "\n\nRegards,\n" . ($this->user['full_name'] ?? 'Dhrub Foundation');

        return "⚠️ **Confirm Email:**\n\n| | |\n|---|---|\n| To | {$to} |\n| Subject | {$subject} |\n\nReply **yes** to send, or **no** to cancel.\n_[Pending: send_email to={$to} subject=\"{$subject}\"]_";
    }

    private function handleSendSms(string $msg, array $e, string $cid): string {
        $phone   = $e['phone'] ?? '';
        if (!$phone) return "To send an SMS, I need the **recipient's mobile number**. Please provide a 10-digit Indian mobile number.";

        if (!function_exists('sendSMS')) {
            try { require_once __DIR__ . '/helpers.php'; } catch (\Throwable $ex) {}
        }
        if (!function_exists('sendSMS')) return "❌ SMS helper (Fast2SMS) is not configured.";

        return "⚠️ **Confirm SMS:**\n\n| | |\n|---|---|\n| To | {$phone} |\n\nWhat **message** should I send? (Reply with the message text and then confirm)";
    }

    private function handleSendWhatsapp(string $msg, array $e, string $cid): string {
        $phone = $e['phone'] ?? '';
        if (!$phone) return "To send a WhatsApp message, I need the **recipient's mobile number** (with country code, e.g. 919876543210).";

        if (!function_exists('sendWhatsApp')) {
            try { require_once __DIR__ . '/helpers.php'; } catch (\Throwable $ex) {}
        }
        if (!function_exists('sendWhatsApp')) return "❌ WhatsApp helper (Evolution API) is not configured in .env.";

        return "⚠️ **Confirm WhatsApp:**\n\n| | |\n|---|---|\n| To | {$phone} |\n\nWhat **message** should I send? (Reply with the message text and then confirm)";
    }

    // ── Clear Chat ────────────────────────────────────────────────────────────

    private function handleClearChat(string $msg, array $e, string $cid): string {
        return "__CLEAR_CHAT__"; // signal to main controller
    }

    // ── Unknown ───────────────────────────────────────────────────────────────

    private function handleUnknown(string $msg, array $e, string $cid): string {
        $lower = strtolower($msg);

        // Handle pending confirmations from prior turn
        if (preg_match('/^(yes|confirm|ok|proceed|haan|ha|sure|go ahead|do it|yep|yup)\b/i', $msg)) {
            return $this->executePendingAction($cid);
        }
        if (preg_match('/^(no|cancel|nahi|na|stop|abort|don\'?t)\b/i', $msg)) {
            $this->clearPending($cid);
            return "Okay, action cancelled. Is there anything else I can help you with?";
        }

        // Try to give a helpful nudge
        if (str_contains($lower, 'total') || str_contains($lower, 'how much') || str_contains($lower, 'how many')) {
            return "Could you be more specific? For example:\n- _\"How much total donations have we received?\"_\n- _\"How many active donors do we have?\"_\n- _\"Show me the dashboard summary\"_";
        }

        return "I'm not sure I understood that. Here are some things I can help with:\n\n" .
               "- **Show dashboard** — quick stats overview\n" .
               "- **List donations** / **List donors** / **List employees**\n" .
               "- **Record a donation** / **Add an expense**\n" .
               "- **Generate payslip** / **Generate receipt**\n" .
               "- **Send email / SMS / WhatsApp**\n\n" .
               "Type **help** to see all commands!";
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PENDING ACTION EXECUTION (multi-turn confirmations)
    // ════════════════════════════════════════════════════════════════════════

    private function executePendingAction(string $cid): string {
        // Look for the last assistant message with a [Pending: ...] tag
        $pending = $this->getLastPendingAction($cid);
        if (!$pending) {
            return "I don't have a pending action to confirm. What would you like to do?";
        }

        // Parse pending action
        $action = $pending['action'];
        $args   = $pending['args'];

        switch ($action) {
            case 'record_donation':
                try {
                    $stmt = $this->db->prepare("INSERT INTO donations (donor_id, amount, payment_method, transaction_id, status, created_at) VALUES (?,?,?,?,'completed',NOW())");
                    $stmt->execute([$args['donor_id'], $args['amount'], $args['method'], $args['txn'] ?? '']);
                    $newId = $this->db->lastInsertId();
                    $this->db->prepare("UPDATE donors SET total_donated=COALESCE(total_donated,0)+? WHERE id=?")->execute([$args['amount'], $args['donor_id']]);
                    return "✅ Donation of **₹" . number_format((float)$args['amount'], 2) . "** recorded successfully! (ID #{$newId})";
                } catch (\Throwable $e) { return "❌ Failed: " . $e->getMessage(); }

            case 'update_salary':
                try {
                    $stmt = $this->db->prepare("UPDATE employees SET basic_salary=? WHERE id=?");
                    $stmt->execute([$args['salary'], $args['emp_id']]);
                    return "✅ Salary updated to **₹" . number_format((float)$args['salary'], 2) . "** successfully!";
                } catch (\Throwable $e) { return "❌ Failed: " . $e->getMessage(); }

            case 'send_email':
                if (!function_exists('sendEmail')) {
                    try { require_once __DIR__ . '/helpers.php'; } catch (\Throwable $e) {}
                }
                if (!function_exists('sendEmail')) return "❌ PHPMailer not installed.";
                $body = "This is a reminder from Dhrub Foundation. Please take the required action.\n\nRegards,\n" . ($this->user['full_name'] ?? 'Team');
                return sendEmail($args['to'], $args['subject'], nl2br($body));

            default:
                return "I couldn't determine which action to execute. Please try again.";
        }
    }

    private function getLastPendingAction(string $cid): ?array {
        // Scan last few assistant messages for [Pending: ...] marker
        foreach (array_reverse($this->history) as $h) {
            if ($h['role'] !== 'assistant') continue;
            if (preg_match('/\[Pending:\s*(\w+)\s+(.+?)\]/', $h['content'], $m)) {
                $action = $m[1];
                $argStr = $m[2];
                $args   = [];
                preg_match_all('/(\w+)=([^\s]+)/', $argStr, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $val = trim($p[2], '"\'');
                    $args[$p[1]] = is_numeric($val) ? (float)$val : $val;
                }
                return ['action' => $action, 'args' => $args];
            }
        }
        return null;
    }

    private function clearPending(string $cid): void {
        // Nothing to do — pending markers live in conversation history
    }

    // ════════════════════════════════════════════════════════════════════════
    //  UTILITIES
    // ════════════════════════════════════════════════════════════════════════

    private function findDonorIdByName(string $name): ?int {
        try {
            $stmt = $this->db->prepare("SELECT id FROM donors WHERE full_name LIKE ? LIMIT 1");
            $stmt->execute(["%{$name}%"]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
