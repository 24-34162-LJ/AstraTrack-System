<?php
/** Edit shipment metadata; stage changes remain in shipment_status.php. */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
require_once __DIR__ . '/function/log_activity.php';
[$userId, $fullName, $role] = requireStaffOrAdmin($conn);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$id = (int)($_GET['id'] ?? $_POST['shipment_id'] ?? 0);
if ($id < 1) { http_response_code(404); exit('Shipment not found.'); }

$load = $conn->prepare('SELECT s.*, c.client_name, u.full_name AS staff_name FROM shipments s JOIN clients c ON c.client_id=s.client_id LEFT JOIN users u ON u.user_id=s.assigned_staff_id WHERE s.shipment_id=?');
$load->execute([$id]);
$ship = $load->fetch();
if (!$ship) { http_response_code(404); exit('Shipment not found.'); }
$isAssigned = (int)($ship['assigned_staff_id'] ?? 0) === $userId;
if ($role === 'staff' && !empty($ship['assigned_staff_id']) && !$isAssigned) {
    $bellDot = hasRecentUpdate($conn);
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Edit Shipment | AstraTrack</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/staff.css"><link rel="stylesheet" href="assets/css/shipments.css"><link rel="stylesheet" href="assets/css/shipment_edit.css"></head><body class="fig"><div class="layout"><?php renderSidebar($role,'shipments',$fullName); ?><div class="fig-main"><?php renderTopnav([['Dashboard',homePage($role)],['Shipment Management',shipListPage($role)],['Shipment Details','shipment_view.php?id='.$id],['Edit',null]],$fullName,$bellDot); ?><main class="page edit-page"><header class="page-head"><div><h1>Edit Shipment</h1><p class="ref-row"><strong><?= e($ship['reference_no']) ?></strong> <?= e($ship['client_name']) ?> <?= badge((statusInfo($ship['current_status']))[0],(statusInfo($ship['current_status']))[1]) ?></p></div><p class="soft-text">Assigned to <?= e($ship['staff_name'] ?: 'another staff member') ?></p></header><section class="fcard edit-restricted"><span class="restricted-icon"><?= icon('shield') ?></span><span class="eyebrow">Access restricted</span><h2>You can’t edit this shipment</h2><p>This shipment is assigned to <?= e($ship['staff_name'] ?: 'another staff member') ?>. Staff may edit only their assigned shipments or unassigned shipments.</p><div class="restricted-ref"><strong><?= e($ship['reference_no']) ?></strong><span>Assigned to <?= e($ship['staff_name'] ?: 'another staff member') ?></span></div><a class="btn btn-primary" href="<?= e(shipListPage($role)) ?>">Back to Shipments</a><small>If this assignment is incorrect, contact an administrator.</small></section></main></div></div></body></html>
    <?php exit;
}
$currentStage = stageByName($ship['current_status']);
$isArchived = !empty($ship['archived_at']);
$isStatusClosed = $currentStage && !empty($currentStage['is_closed']);
$isReadOnly = $isArchived || ($isStatusClosed && $role !== 'admin');
$clientRows = $conn->query("SELECT client_id, client_name, status FROM clients ORDER BY client_name")->fetchAll();
$staffLabel = $ship['staff_name'] ?: 'Unassigned';

$values = [
 'client_id'=>(string)$ship['client_id'], 'transaction_type'=>(string)$ship['transaction_type'], 'mode'=>(string)$ship['mode'], 'remarks'=>(string)($ship['remarks'] ?? ''),
 'bl_awb_no'=>(string)($ship['bl_awb_no'] ?? ''), 'total_packages'=>(string)($ship['total_packages'] ?? ''), 'total_gross_weight'=>(string)($ship['total_gross_weight'] ?? ''), 'shipper_name'=>(string)($ship['shipper_name'] ?? ''), 'consignee_name'=>(string)($ship['consignee_name'] ?? ''), 'vessel_or_flight'=>(string)($ship['vessel_or_flight'] ?? ''), 'port_of_loading'=>(string)($ship['port_of_loading'] ?? ''), 'port_of_discharge'=>(string)($ship['port_of_discharge'] ?? ''), 'hs_code'=>(string)($ship['hs_code'] ?? ''), 'measurement_cbm'=>(string)($ship['measurement_cbm'] ?? ''), 'incoterm'=>(string)($ship['incoterm'] ?? ''),
 'invoice_no'=>(string)($ship['invoice_no'] ?? ''), 'currency'=>(string)($ship['currency'] ?? 'USD'), 'invoice_value'=>(string)($ship['invoice_value'] ?? ''), 'freight_amount'=>(string)($ship['freight_amount'] ?? ''), 'fob_value'=>(string)($ship['fob_value'] ?? ''),
 'eta_date'=>(string)($ship['eta_date'] ?? ''), 'first_free_day'=>(string)($ship['first_free_day'] ?? ''), 'delivery_due_date'=>(string)($ship['delivery_due_date'] ?? ''),
];
$containerQuery=$conn->prepare('SELECT container_no, seal_no, size_type FROM containers WHERE shipment_id=? ORDER BY container_id'); $containerQuery->execute([$id]);
$containerRows=array_map(static fn($r)=>['container_no'=>(string)$r['container_no'],'seal_no'=>(string)($r['seal_no']??''),'size_type'=>(string)($r['size_type']??'')],$containerQuery->fetchAll());
$originalContainerRows=$containerRows;
$errors=[]; $saved=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if ($isReadOnly) $errors['_form']=$isArchived ? 'Archived shipments are read-only.' : 'This shipment is closed. Ask an administrator to edit its metadata.';
    if (!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??''))) $errors['_form']='Your session expired. Reload the page and try again.';
    foreach ($values as $key=>$old) { if (isset($_POST[$key]) && !is_array($_POST[$key])) $values[$key]=trim((string)$_POST[$key]); }
    foreach (['total_gross_weight','measurement_cbm','invoice_value','freight_amount','fob_value'] as $numberKey) $values[$numberKey]=str_replace([',',' '],'',$values[$numberKey]);
    $allowedType=['import','export']; $allowedMode=['sea','air','land'];
    $clientId=(int)$values['client_id'];
    $clientCheck=$conn->prepare("SELECT client_id FROM clients WHERE client_id=? AND status='active'"); $clientCheck->execute([$clientId]);
    if (!$clientCheck->fetchColumn()) $errors['client_id']='Select an active client.';
    if (!in_array($values['transaction_type'],$allowedType,true)) $errors['transaction_type']='Select Import or Export.';
    if (!in_array($values['mode'],$allowedMode,true)) $errors['mode']='Select Sea, Air or Land.';
    $bl=trim($values['bl_awb_no']);
    if (mb_strlen($bl)>50) $errors['bl_awb_no']='B/L or AWB number must be 50 characters or fewer.';
    elseif ($bl==='' && in_array($values['mode'],['sea','air'],true)) $errors['bl_awb_no']=$values['mode']==='air'?'AWB number is required for Air.':'B/L number is required for Sea.';
    if ($bl!=='') { $dupe=$conn->prepare('SELECT reference_no FROM shipments WHERE bl_awb_no=? AND shipment_id<>? LIMIT 1'); $dupe->execute([$bl,$id]); $other=$dupe->fetchColumn(); if ($other) $errors['bl_awb_no']='This B/L / AWB number already belongs to another shipment. Your saved number is '.($ship['bl_awb_no'] ?: 'blank').'.'; }
    $limits=['remarks'=>null,'shipper_name'=>150,'consignee_name'=>150,'vessel_or_flight'=>100,'port_of_loading'=>100,'port_of_discharge'=>100,'hs_code'=>20,'incoterm'=>10,'invoice_no'=>50];
    foreach ($limits as $key=>$max) if ($max!==null && mb_strlen($values[$key])>$max) $errors[$key]='Maximum length is '.$max.' characters.';
    if ($values['currency']==='' || !preg_match('/^[A-Z]{3}$/',strtoupper($values['currency']))) $errors['currency']='Enter a 3-letter currency code.'; else $values['currency']=strtoupper($values['currency']);
    foreach (['eta_date'=>'Estimated Arrival','first_free_day'=>'First Free Day','delivery_due_date'=>'Delivery Due Date'] as $dateKey=>$dateLabel) {
        if ($values[$dateKey] === '') continue;
        $parsedDate=DateTimeImmutable::createFromFormat('!Y-m-d',$values[$dateKey]); $dateErrors=DateTimeImmutable::getLastErrors();
        if (!$parsedDate || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $parsedDate->format('Y-m-d') !== $values[$dateKey]) $errors[$dateKey]='Enter a valid '.$dateLabel.' date.';
    }
    if ($values['total_packages']!=='' && (!preg_match('/^\d+$/',$values['total_packages']) || (float)$values['total_packages']>2147483647)) $errors['total_packages']='Packages must be a whole number from 0 to 2,147,483,647.';
    foreach (['total_gross_weight'=>9,'measurement_cbm'=>9] as $key=>$wholeDigits) if ($values[$key]!=='' && (!preg_match('/^\d{1,'.$wholeDigits.'}(?:\.\d{1,3})?$/',$values[$key]))) $errors[$key]='Enter a non-negative number with up to 3 decimal places.';
    foreach (['invoice_value'=>13,'freight_amount'=>13,'fob_value'=>13] as $key=>$wholeDigits) if ($values[$key]!=='' && (!preg_match('/^\d{1,'.$wholeDigits.'}(?:\.\d{1,2})?$/',$values[$key]))) $errors[$key]='Enter a non-negative amount with up to 2 decimal places.';
    $postedRows=$_POST['containers']??[]; if (!is_array($postedRows)) $postedRows=[];
    $containerRows=[]; $seen=[];
    foreach ($postedRows as $i=>$r) {
        if (!is_array($r)) continue;
        $row=['container_no'=>trim((string)($r['container_no']??'')),'seal_no'=>trim((string)($r['seal_no']??'')),'size_type'=>trim((string)($r['size_type']??''))];
        if ($row['container_no']==='' && $row['seal_no']==='' && $row['size_type']==='') continue;
        if ($row['container_no']==='') $errors['containers']='Enter a container number or clear the incomplete row.';
        if (mb_strlen($row['container_no'])>20 || mb_strlen($row['seal_no'])>30 || mb_strlen($row['size_type'])>20) $errors['containers']='Container number, seal number or size/type exceeds its database column limit.';
        $fold=strtoupper($row['container_no']); if ($fold!=='' && isset($seen[$fold])) $errors['containers']='Container numbers must be unique within this shipment.'; $seen[$fold]=true;
        $containerRows[]=$row;
    }
    if ($values['mode']!=='sea' && $containerRows) $errors['containers']='Sea containers only. Remove all container rows before changing this shipment to Air or Land.';
    if (!$errors) {
        try {
            $changedFields=[]; $auditChanges=[];
            $fieldLabels=['client_id'=>'Client','transaction_type'=>'Type','mode'=>'Mode','remarks'=>'Description / Remarks','bl_awb_no'=>'B/L or AWB No.','total_packages'=>'Packages','total_gross_weight'=>'Gross Weight (kg)','shipper_name'=>'Shipper','consignee_name'=>'Consignee','vessel_or_flight'=>'Vessel or Flight','port_of_loading'=>'Port of Loading','port_of_discharge'=>'Port of Discharge','hs_code'=>'HS Code','measurement_cbm'=>'Measurement (CBM)','incoterm'=>'Incoterm','invoice_no'=>'Invoice No.','currency'=>'Currency','invoice_value'=>'Invoice Value','freight_amount'=>'Freight','fob_value'=>'FOB Value','eta_date'=>'Estimated Arrival','first_free_day'=>'First Free Day','delivery_due_date'=>'Delivery Due Date'];
            if ($values['mode']==='land') { $fieldLabels['bl_awb_no']='Waybill No.'; $fieldLabels['vessel_or_flight']='Truck Plate'; $fieldLabels['port_of_loading']='Origin'; $fieldLabels['port_of_discharge']='Destination'; }
            elseif ($values['mode']==='air') { $fieldLabels['bl_awb_no']='AWB No.'; $fieldLabels['vessel_or_flight']='Airline / Flight'; $fieldLabels['port_of_loading']='Airport of Departure'; $fieldLabels['port_of_discharge']='Airport of Arrival'; }
            else { $fieldLabels['bl_awb_no']='B/L No.'; $fieldLabels['vessel_or_flight']='Vessel / Voyage'; }
            $clientNames=array_column($clientRows,'client_name','client_id');
            foreach($fieldLabels as $key=>$label) {
                $old=(string)($ship[$key]??''); $new=(string)($values[$key]??'');
                if ($key==='client_id') { $old=(string)($clientNames[(int)$old]??$old); $new=(string)($clientNames[(int)$new]??$new); }
                if (in_array($key,['transaction_type','mode'],true)) { $old=ucfirst($old); $new=ucfirst($new); }
                if ($old!==$new) { $changedFields[]=$label; $auditChanges[$label]=['old'=>$old!==''?$old:'—','new'=>$new!==''?$new:'—']; }
            }
            $beforeContainers=array_map(static fn($r)=>[$r['container_no'],$r['seal_no'],$r['size_type']],$originalContainerRows);
            $afterContainers=array_map(static fn($r)=>[$r['container_no'],$r['seal_no'],$r['size_type']],$containerRows);
            if ($beforeContainers!==$afterContainers) {
                $changedFields[]='Containers';
                $auditChanges['Containers']=['old'=>array_map(static fn($r)=>implode(' · ',array_filter($r,static fn($v)=>$v!=='')),$beforeContainers),'new'=>array_map(static fn($r)=>implode(' · ',array_filter($r,static fn($v)=>$v!=='')),$afterContainers)];
            }
            $conn->beginTransaction();
            $locked=$conn->prepare('SELECT current_status, assigned_staff_id, archived_at FROM shipments WHERE shipment_id=? FOR UPDATE'); $locked->execute([$id]); $lockedShip=$locked->fetch();
            $lockedStage=$lockedShip ? stageByName($lockedShip['current_status']) : null;
            if (!$lockedShip) throw new LogicException('This shipment no longer exists.');
            if (!empty($lockedShip['archived_at'])) throw new LogicException('This shipment was archived while you were editing. Archived shipments are read-only.');
            if ($role !== 'admin' && $lockedStage && !empty($lockedStage['is_closed'])) throw new LogicException('This shipment was closed while you were editing. Ask an administrator to edit its metadata.');
            if ($role==='staff' && !empty($lockedShip['assigned_staff_id']) && (int)$lockedShip['assigned_staff_id']!==$userId) throw new LogicException('This shipment was reassigned while you were editing. Only the assigned staff member or an administrator can save it.');
            $update=$conn->prepare('UPDATE shipments SET client_id=?, transaction_type=?, mode=?, bl_awb_no=?, shipper_name=?, consignee_name=?, vessel_or_flight=?, port_of_loading=?, port_of_discharge=?, hs_code=?, total_packages=?, total_gross_weight=?, measurement_cbm=?, incoterm=?, invoice_no=?, currency=?, invoice_value=?, freight_amount=?, fob_value=?, remarks=?, eta_date=?, first_free_day=?, delivery_due_date=? WHERE shipment_id=?');
            $update->execute([$clientId,$values['transaction_type'],$values['mode'],$bl!==''?$bl:null,$values['shipper_name']!==''?$values['shipper_name']:null,$values['consignee_name']!==''?$values['consignee_name']:null,$values['vessel_or_flight']!==''?$values['vessel_or_flight']:null,$values['port_of_loading']!==''?$values['port_of_loading']:null,$values['port_of_discharge']!==''?$values['port_of_discharge']:null,$values['hs_code']!==''?$values['hs_code']:null,$values['total_packages']!==''?(int)$values['total_packages']:null,$values['total_gross_weight']!==''?$values['total_gross_weight']:null,$values['measurement_cbm']!==''?$values['measurement_cbm']:null,$values['incoterm']!==''?$values['incoterm']:null,$values['invoice_no']!==''?$values['invoice_no']:null,$values['currency'],$values['invoice_value']!==''?$values['invoice_value']:null,$values['freight_amount']!==''?$values['freight_amount']:null,$values['fob_value']!==''?$values['fob_value']:null,$values['remarks']!==''?$values['remarks']:null,$values['eta_date']!==''?$values['eta_date']:null,$values['first_free_day']!==''?$values['first_free_day']:null,$values['delivery_due_date']!==''?$values['delivery_due_date']:null,$id]);
            if ((int)$ship['client_id'] !== $clientId) {
                // Keep client ownership aligned across the shipment, transaction, and billing records.
                $conn->prepare('UPDATE transactions SET client_id=? WHERE shipment_id=?')->execute([$clientId,$id]);
                $conn->prepare('UPDATE billings SET client_id=? WHERE shipment_id=?')->execute([$clientId,$id]);
                $transactionQuery=$conn->prepare('SELECT transaction_id FROM transactions WHERE shipment_id=?');
                $transactionQuery->execute([$id]);
                $history=$conn->prepare('INSERT INTO transaction_history (transaction_id,event_type,description,performed_by) VALUES (?,?,?,?)');
                foreach ($transactionQuery->fetchAll(PDO::FETCH_COLUMN) as $transactionId) {
                    $history->execute([(int)$transactionId,'Client changed','Client on shipment '.$ship['reference_no'].' changed from '.$ship['client_name'].' to '.($clientNames[$clientId] ?? 'new client'),$userId]);
                }
            }
            $conn->prepare('DELETE FROM containers WHERE shipment_id=?')->execute([$id]);
            if ($values['mode']==='sea') { $insert=$conn->prepare('INSERT INTO containers (shipment_id,container_no,seal_no,size_type) VALUES (?,?,?,?)'); foreach ($containerRows as $row) $insert->execute([$id,$row['container_no'],$row['seal_no']!==''?$row['seal_no']:null,$row['size_type']!==''?$row['size_type']:null]); }
            $changeSummary=$changedFields ? 'metadata updated: '.implode(', ',array_slice($changedFields,0,8)) : 'metadata saved';
            logActivity($conn,'Shipment Update',$ship['reference_no'].' '.$changeSummary,$auditChanges);
            if (array_intersect($changedFields, ['Estimated Arrival','First Free Day','Delivery Due Date'])) createShipmentNotifications($conn, array_merge($ship, ['client_id'=>$clientId]), 'date', 'Shipment dates updated', $ship['reference_no'] . ' key dates were updated.', $userId);
            $conn->commit();
            header('Location: shipment_view.php?id='.$id.'&shipment_saved=1'); exit;
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('shipment_edit save failed: '.$ex->getMessage());
            if ($ex instanceof LogicException) $errors['_form']=$ex->getMessage();
            elseif ($ex instanceof PDOException && $ex->getCode()==='23000') $errors['bl_awb_no']='This B/L / AWB number is already assigned to another shipment.';
            else $errors['_form']='Shipment changes could not be saved. No changes were applied. Please review the entries and try again.';
        }
    }
}
$bellDot=hasRecentUpdate($conn);
$detailsLink='shipment_view.php?id='.$id;
$currencies=['USD','PHP','EUR','JPY','CNY','SGD','HKD','GBP','AUD','CAD']; if ($values['currency']!=='' && !in_array($values['currency'],$currencies,true)) $currencies[]=$values['currency'];
$disabled=$isReadOnly?' disabled':'';
function editInput(string $name,string $label,array $values,array $errors,string $type='text',string $extra=''):void { global $disabled; $has=isset($errors[$name]); echo '<label class="edit-field'.($has?' has-error':'').'" data-field="'.e($name).'" for="edit_'.e($name).'"><span>'.e($label).'</span><input id="edit_'.e($name).'" name="'.e($name).'" type="'.e($type).'" value="'.e($values[$name]??'').'" '.$extra.$disabled.($has?' aria-invalid="true"':'').'><small class="edit-error">'.($has?e($errors[$name]):'').'</small></label>'; }
function editSelect(string $name,string $label,array $options,array $values,array $errors,string $disabled=''):void { $has=isset($errors[$name]); echo '<label class="edit-field'.($has?' has-error':'').'" data-field="'.e($name).'" for="edit_'.e($name).'"><span>'.e($label).'</span><select id="edit_'.e($name).'" name="'.e($name).'" required'.$disabled.($has?' aria-invalid="true"':'').'><option value="">Select…</option>'; foreach ($options as $value=>$text) echo '<option value="'.e((string)$value).'"'.((string)($values[$name]??'')===(string)$value?' selected':'').'>'.e((string)$text).'</option>'; echo '</select><small class="edit-error">'.($has?e($errors[$name]):'').'</small></label>'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf" content="<?= e($_SESSION['csrf']) ?>"><title>Edit <?= e($ship['reference_no']) ?> | AstraTrack</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/staff.css"><link rel="stylesheet" href="assets/css/shipments.css"><link rel="stylesheet" href="assets/css/shipment_edit.css?v=20261024-2"></head><body class="fig"><div class="layout"><?php renderSidebar($role,'shipments',$fullName); ?><div class="fig-main"><?php renderTopnav([['Dashboard',homePage($role)],['Shipment Management',shipListPage($role)],['Shipment Details',$detailsLink],['Edit',null]],$fullName,$bellDot); ?><main class="page edit-page" data-closed="<?= $isReadOnly?'1':'0' ?>" data-reference="<?= e($ship['reference_no']) ?>">
<header class="page-head edit-head"><div><h1>Edit Shipment</h1><p class="ref-row"><strong><?= e($ship['reference_no']) ?></strong><span><?= e($ship['client_name']) ?></span><?= badge((statusInfo($ship['current_status']))[0],(statusInfo($ship['current_status']))[1]) ?></p></div><div class="edit-head-meta"><span>Assigned to <?= e($staffLabel) ?></span><small>Shipment metadata · Status is managed separately</small></div></header>
<?php if ($errors): $errorCount=count(array_filter(array_keys($errors),static fn($k)=>$k!=='_form' && $k!=='containers')); ?><div class="edit-banner" role="alert"><strong><?= $errorCount ? 'Please correct '.$errorCount.' field(s).' : 'Unable to save shipment.' ?></strong> <?= e($errors['_form']??$errors['containers']??'Review the highlighted fields below.') ?></div><?php endif; ?>
<?php if ($isArchived): ?><div class="closed-banner"><span><?= icon('shield') ?></span><div><strong>This shipment is archived</strong><small>Archived shipment fields are read-only.</small></div><?php if ($role==='admin'): ?><a href="shipment_view.php?id=<?= $id ?>">Open shipment actions</a><?php endif; ?></div><?php elseif ($isStatusClosed): ?><div class="closed-banner"><span><?= icon('shield') ?></span><div><strong>This shipment is <?= e($ship['current_status']) ?></strong><small><?= $role==='admin'?'Administrator metadata edits are allowed. Shipment status stays unchanged and edits are recorded in the audit trail.':'This shipment is closed. Ask an administrator to edit its metadata.' ?></small></div><?php if ($role==='admin'): ?><span class="soft-text">Status remains <?= e($ship['current_status']) ?></span><?php endif; ?></div><?php endif; ?>
<form id="shipmentEditForm" method="post" action="shipment_edit.php?id=<?= $id ?>" novalidate><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="shipment_id" value="<?= $id ?>">
<section class="fcard edit-card"><div class="edit-card-head"><span class="section-number">01</span><h2>Shipment Basics</h2><small><?= $isReadOnly?'Read-only':'* Required fields' ?></small></div><div class="edit-grid">
<label class="edit-field<?= isset($errors['client_id'])?' has-error':'' ?>" data-field="client_id" for="edit_client_id"><span>Client *</span><select id="edit_client_id" name="client_id" required<?= $disabled ?>><option value="">Select a client</option><?php foreach($clientRows as $c): if($c['status']!=='active' && (int)$c['client_id']!==(int)$values['client_id']) continue; ?><option value="<?= (int)$c['client_id'] ?>"<?= (string)$c['client_id']===$values['client_id']?' selected':'' ?>><?= e($c['client_name']) ?></option><?php endforeach; ?></select><small class="edit-error"><?= e($errors['client_id']??'') ?></small></label>
<?php editSelect('transaction_type','Type * · Import / Export',['import'=>'Import','export'=>'Export'],$values,$errors,$disabled); editSelect('mode','Mode * · Sea / Air / Land',['sea'=>'Sea','air'=>'Air','land'=>'Land'],$values,$errors,$disabled); ?>
<label class="edit-field edit-wide<?= isset($errors['remarks'])?' has-error':'' ?>" data-field="remarks" for="edit_remarks"><span>Description / Remarks</span><textarea id="edit_remarks" name="remarks" maxlength="5000"<?= $disabled ?>><?= e($values['remarks']) ?></textarea><small class="edit-error"><?= e($errors['remarks']??'') ?></small></label></div></section>
<section class="fcard edit-card"><div class="edit-card-head"><span class="section-number">02</span><h2>Cargo and Route</h2><small data-mode-label><?= e(ucfirst($values['mode'])) ?> freight</small></div><div class="edit-grid">
<?php editInput('bl_awb_no','B/L or AWB No.',$values,$errors,'text','maxlength="50" data-bl'); editInput('total_packages','Packages',$values,$errors,'number','min="0" max="2147483647" step="1"'); editInput('total_gross_weight','Gross Weight (kg)',$values,$errors,'number','min="0" step="0.001"'); ?>
<?php editInput('shipper_name','Shipper',$values,$errors,'text','maxlength="150"'); editInput('consignee_name','Consignee',$values,$errors,'text','maxlength="150"'); editInput('vessel_or_flight','Vessel or Flight',$values,$errors,'text','maxlength="100"'); ?>
<?php editInput('port_of_loading','Port of Loading',$values,$errors,'text','maxlength="100"'); editInput('port_of_discharge','Port of Discharge',$values,$errors,'text','maxlength="100"'); editInput('hs_code','HS Code',$values,$errors,'text','maxlength="20"'); ?>
<?php editInput('measurement_cbm','Measurement (CBM)',$values,$errors,'number','min="0" step="0.001"'); editInput('incoterm','Incoterm',$values,$errors,'text','maxlength="10"'); ?><p class="field-help">Keep identifiers consistent with the shipment’s supporting documents.</p></div></section>
<section class="fcard edit-card"><div class="edit-card-head"><span class="section-number">03</span><h2>Invoice and Values</h2><small>All monetary values in <?= e($values['currency']) ?></small></div><div class="edit-grid">
<?php editInput('invoice_no','Invoice No.',$values,$errors,'text','maxlength="50"'); ?>
<label class="edit-field<?= isset($errors['currency'])?' has-error':'' ?>" data-field="currency" for="edit_currency"><span>Currency</span><select id="edit_currency" name="currency"<?= $disabled ?>><?php foreach($currencies as $c): ?><option value="<?= e($c) ?>"<?= $values['currency']===$c?' selected':'' ?>><?= e($c) ?></option><?php endforeach; ?></select><small class="edit-error"><?= e($errors['currency']??'') ?></small></label>
<?php editInput('invoice_value','Invoice Value',$values,$errors,'number','min="0" step="0.01"'); editInput('freight_amount','Freight',$values,$errors,'number','min="0" step="0.01"'); editInput('fob_value','FOB Value',$values,$errors,'number','min="0" step="0.01"'); ?><p class="field-help">Amounts follow the selected currency. No currency conversion is applied.</p></div></section>
<section class="fcard edit-card"><div class="edit-card-head"><span class="section-number">04</span><h2>Key Dates</h2><small>Leave dates blank until confirmed.</small></div><div class="edit-grid">
<?php foreach(['eta_date'=>'Estimated Arrival','first_free_day'=>'First Free Day','delivery_due_date'=>'Delivery Due Date'] as $dateField=>$dateLabel): ?><label class="edit-field<?= isset($errors[$dateField])?' has-error':'' ?>" data-field="<?= e($dateField) ?>" for="edit_<?= e($dateField) ?>"><span><?= e($dateLabel) ?></span><input id="edit_<?= e($dateField) ?>" name="<?= e($dateField) ?>" type="date" value="<?= e($values[$dateField]) ?>"<?= $disabled ?>><small class="edit-error"><?= e($errors[$dateField]??'') ?></small></label><?php endforeach; ?>
<p class="field-help edit-wide">Dates are shown to clients and used to flag upcoming arrivals and overdue deliveries.</p></div></section>
<section class="fcard edit-card" id="containersCard"><div class="edit-card-head"><span class="section-number">05</span><h2>Containers</h2><?php if(!$isReadOnly): ?><button class="btn btn-secondary" type="button" data-add-container>+ Add Row</button><?php endif; ?></div><div class="container-grid"><div class="container-head"><span>Container No.</span><span>Seal No.</span><span>Size/Type</span><span>Action</span></div><div id="containerRows"><?php foreach($containerRows as $i=>$r): ?><div class="container-row"><label><span class="sr-only">Container No.</span><input name="containers[<?= $i ?>][container_no]" value="<?= e($r['container_no']) ?>" maxlength="20" placeholder="Container No."<?= $disabled ?>></label><label><span class="sr-only">Seal No.</span><input name="containers[<?= $i ?>][seal_no]" value="<?= e($r['seal_no']) ?>" maxlength="30" placeholder="Seal No."<?= $disabled ?>></label><label><span class="sr-only">Size/Type</span><input name="containers[<?= $i ?>][size_type]" value="<?= e($r['size_type']) ?>" maxlength="20" placeholder="Size/Type"<?= $disabled ?>></label><?php if(!$isReadOnly): ?><button class="remove-container" type="button" data-remove-container>Remove</button><?php else: ?><span></span><?php endif; ?></div><?php endforeach; ?></div><div class="container-error"><?= e($errors['containers']??'') ?></div><p class="field-help">Sea containers only. Air and Land shipments do not require sea containers. Add a row only when container details are available.</p></div></section>
</form>
<footer class="edit-footer"><span id="editState" aria-live="polite"><?= $isReadOnly?'Read-only · '.($isArchived?'Archived shipment':'Closed shipment'):'No changes to save' ?></span><div><a class="btn btn-secondary" href="<?= e($detailsLink) ?>" data-cancel><?= $isReadOnly?'Back to Details':'Cancel' ?></a><button class="btn btn-primary" type="submit" form="shipmentEditForm" id="saveShipment"<?= ($isReadOnly||$_SERVER['REQUEST_METHOD']!=='POST'||$errors)?' disabled':'' ?>>Save Changes</button></div></footer>
<dialog class="leave-dialog" id="leaveDialog"><div class="leave-mark">!</div><h2>Leave page without saving?</h2><p>Your unsaved changes to <?= e($ship['reference_no']) ?> will be lost. Stay on this page to keep editing your draft.</p><div><button class="btn btn-danger-outline" type="button" data-leave-confirm>Leave without saving</button><button class="btn btn-primary" type="button" data-leave-stay>Stay</button></div></dialog>
</main></div></div><script src="assets/js/shipment_edit.js?v=20261024-1"></script></body></html>
