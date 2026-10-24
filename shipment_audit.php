<?php
/** Read shipment-scoped audit events for the admin/staff audit drawer. */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
[$userId,$fullName,$role]=requireStaffOrAdmin($conn,true);
header('Content-Type: application/json; charset=utf-8');
function auditJson(int $status,array $data):void { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
$id=max(0,(int)($_GET['shipment_id']??0));
$filter=(string)($_GET['filter']??'all');
if (!in_array($filter,['all','status','documents','edits'],true)) auditJson(400,['error'=>'Invalid activity filter.']);
$offset=max(0,(int)($_GET['offset']??0)); $limit=8;
$shipmentQuery=$conn->prepare('SELECT reference_no,assigned_staff_id FROM shipments WHERE shipment_id=?'); $shipmentQuery->execute([$id]); $shipment=$shipmentQuery->fetch();
if (!$shipment) auditJson(404,['error'=>'Shipment not found.']);
if ($role==='staff' && !empty($shipment['assigned_staff_id']) && (int)$shipment['assigned_staff_id']!==$userId) auditJson(403,['error'=>'You cannot view this shipment activity.']);
$reference=(string)$shipment['reference_no']; $events=[];
$matches=static function(array $event)use($filter):bool{return $filter==='all'||$event['category']===$filter;};

$statusQuery=$conn->prepare('SELECT u.update_id,u.stage,u.remarks,u.updated_at,u.updated_by,usr.full_name,usr.role FROM shipment_status_updates u LEFT JOIN users usr ON usr.user_id=u.updated_by WHERE u.shipment_id=? ORDER BY u.updated_at DESC,u.update_id DESC');
$statusQuery->execute([$id]);
foreach($statusQuery->fetchAll() as $r){$event=['key'=>'s-'.$r['update_id'],'category'=>'status','kind'=>'Status','at'=>$r['updated_at'],'actor'=>$r['full_name']?:'System','role'=>$r['role']?:'system','description'=>rtrim((string)$r['stage'],'. ').'.','details'=>null,'note'=>trim((string)$r['remarks'])!==''?(string)$r['remarks']:null]; if($matches($event))$events[]=$event;}

$docQuery=$conn->prepare('SELECT d.document_id,d.doc_type,d.uploaded_at,u.full_name,u.role FROM shipment_documents d LEFT JOIN users u ON u.user_id=d.uploaded_by WHERE d.shipment_id=? ORDER BY d.uploaded_at DESC,d.document_id DESC');
$docQuery->execute([$id]);
foreach($docQuery->fetchAll() as $r){$event=['key'=>'d-'.$r['document_id'],'category'=>'documents','kind'=>'Document','at'=>$r['uploaded_at'],'actor'=>$r['full_name']?:'System','role'=>$r['role']?:'system','description'=>trim((string)$r['doc_type']).' uploaded.','details'=>null]; if($matches($event))$events[]=$event;}

$hasDetails=(int)$conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='activity_log' AND COLUMN_NAME='details_json'")->fetchColumn()>0;
$detailSelect=$hasDetails?'details_json':'NULL AS details_json';
$activityQuery=$conn->prepare("SELECT activity_id,activity_type,description,performed_by_name,performed_by_role,created_at,$detailSelect FROM activity_log WHERE description LIKE ? ORDER BY created_at DESC,activity_id DESC");
$activityQuery->execute(['%'.$reference.'%']);
foreach($activityQuery->fetchAll() as $r){
    $description=(string)$r['description'];
    if(preg_match('/^Status of .* (?:updated to|corrected from) /i',$description)) continue;
    $category=preg_match('/document|uploaded|verified/i',$description)?'documents':'edits';
    $kind='Edit';
    if($category==='documents')$kind='Document';
    elseif(preg_match('/customs/i',$description))$kind='Customs';
    elseif(preg_match('/delivery|trucking/i',$description))$kind='Delivery';
    elseif(preg_match('/reassign|assigned/i',$description))$kind='Assignment';
    $details=null;
    if($hasDetails && !empty($r['details_json'])){$decoded=json_decode((string)$r['details_json'],true);if(is_array($decoded))$details=$decoded;}
    $event=['key'=>'a-'.$r['activity_id'],'category'=>$category,'kind'=>$kind,'at'=>$r['created_at'],'actor'=>$r['performed_by_name']?:'System','role'=>$r['performed_by_role']?:'system','description'=>$description,'details'=>$details];
    if($matches($event))$events[]=$event;
}

// A new upload is represented both by its shipment_documents row and by the richer
// activity_log entry. Keep the activity entry (which includes the original filename)
// and suppress the generic duplicate when the type and upload time match.
$events = array_values(array_filter($events, static function (array $event) use ($events): bool {
    if (!preg_match('/^d-/', (string)($event['key'] ?? ''))) return true;
    $documentType = preg_replace('/ uploaded\.$/', '', (string)($event['description'] ?? ''));
    $documentTime = strtotime((string)($event['at'] ?? ''));
    if ($documentType === '' || $documentTime === false) return true;

    foreach ($events as $candidate) {
        if (!preg_match('/^a-/', (string)($candidate['key'] ?? '')) || ($candidate['category'] ?? '') !== 'documents') continue;
        $needle = 'document uploaded: ' . $documentType . ' (';
        if (stripos((string)($candidate['description'] ?? ''), $needle) === false) continue;
        $activityTime = strtotime((string)($candidate['at'] ?? ''));
        if ($activityTime !== false && abs($activityTime - $documentTime) <= 120) return false;
    }
    return true;
}));

usort($events,static function($a,$b){$date=strcmp((string)$b['at'],(string)$a['at']);return $date!==0?$date:strcmp((string)$b['key'],(string)$a['key']);});
$total=count($events); $items=array_slice($events,$offset,$limit);
auditJson(200,['items'=>$items,'total'=>$total,'nextOffset'=>$offset+count($items),'hasMore'=>$offset+count($items)<$total]);
