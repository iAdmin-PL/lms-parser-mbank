<?php


$layout['pagetitle'] = trans('Cash Operations Import from mBank');
$layout['file'] = $_FILES['file']['name'];

if(isset($_GET['action']) && $_GET['action'] == 'delete')
{
	if($marks = $_POST['marks'])
		foreach($marks as $id)
			$DB->Execute('UPDATE cashimport SET closed = 1 WHERE id = ?', array($id));
}
elseif(isset($_GET['action']) && $_GET['action'] == 'save')
{
	if(!empty($_POST['customer']))
		foreach($_POST['customer'] as $idx => $id) if($id)
			$DB->Execute('UPDATE cashimport SET customerid = ? WHERE id = ?', array($id, $idx));
}
elseif(isset($_POST['marks']))
{
	$marks = $_POST['marks'];
	$customers = $_POST['customer'];
	foreach($marks as $id)
	{
		if(isset($customers[$id]))
		{
			$DB->BeginTrans();
	
			$import = $DB->GetRow('SELECT * FROM cashimport WHERE id = ?', array($id));
	
			$balance['time'] = $import['date'];
			$balance['type'] = 1;
			$balance['value'] = $import['value'];
			$balance['customerid'] = $customers[$id];
			$balance['comment'] = $import['description'];
			$balance['importid'] = $import['id'];
			$balance['sourceid'] = $import['sourceid'];
			
			if($import['value'] > 0 && isset($CONFIG['finances']['cashimport_checkinvoices'])
				&& chkconfig($CONFIG['finances']['cashimport_checkinvoices']))
			{
				if($invoices = $DB->GetAll('SELECT d.id,
						(SELECT SUM(value*count) FROM invoicecontents WHERE docid = d.id) +
						COALESCE((SELECT SUM((a.value+b.value)*(a.count+b.count)) - SUM(b.value*b.count)
							FROM documents dd
							JOIN invoicecontents a ON (a.docid = dd.id)
        						JOIN invoicecontents b ON (dd.reference = b.docid AND a.itemid = b.itemid)
	        					WHERE dd.reference = d.id
		    					GROUP BY dd.reference), 0) AS value
					FROM documents d
					WHERE d.customerid = ? AND d.type = ? AND d.closed = 0
					GROUP BY d.id, d.cdate ORDER BY d.cdate',
					array($customers[$id], DOC_INVOICE)))
				{
					foreach($invoices as $inv)
						$sum += $inv['value'];
					
					$bval = $LMS->GetCustomerBalance($customers[$id]);
					$value = $bval + $import['value'] + $sum;

					foreach($invoices as $inv)
						if($inv['value'] > $value)
							break;
						else
						{
							// close invoice and assigned credit notes
							$DB->Execute('UPDATE documents SET closed = 1
								WHERE id = ? OR reference = ?',
								array($inv['id'], $inv['id']));
							
							$value -= $inv['value'];
						}
				}
			}
			
			$DB->Execute('UPDATE cashimport SET closed = 1 WHERE id = ?', array($id));
			$LMS->AddBalance($balance);

			$DB->CommitTrans();
		}
		else
			$error[$id] = trans('Customer not selected!');
	}
}

$SESSION->save('backto', $_SERVER['QUERY_STRING']);

$SMARTY->assign('divisions', $divisions);
$SMARTY->assign('listdata', isset($listdata) ? $listdata : NULL);
$SMARTY->assign('error', $error);
$SMARTY->assign('customerlist', $LMS->GetCustomerNames());
$SMARTY->assign('sourcelist', 'Synergia Polska');
$SMARTY->display('cashimportmbank.html');

?>
