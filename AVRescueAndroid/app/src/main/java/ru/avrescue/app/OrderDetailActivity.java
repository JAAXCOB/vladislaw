package ru.avrescue.app;

import android.app.*;
import android.os.*;
import android.content.*;
import android.graphics.Bitmap;
import android.net.Uri;
import android.provider.MediaStore;
import android.util.Base64;
import android.widget.*;
import org.json.*;
import java.io.ByteArrayOutputStream;

public class OrderDetailActivity extends Activity {
 protected void onStart(){super.onStart();getWindow().addFlags(android.view.WindowManager.LayoutParams.FLAG_SECURE);}
 LinearLayout box,footer;
 String orderId="";
 JSONObject order=null;
 boolean executor=false;
 String role="client";
 static final int PHOTO_BEFORE_LOADING=120;
 int photoStep=0;String[] photoPaths=new String[4];
 final String[] photoKinds={"order_vehicle_front","order_vehicle_rear","order_vehicle_left","order_vehicle_right"};
 final String[] photoLabels={"спереди","сзади","слева","справа"};

 public void onCreate(Bundle b){
  super.onCreate(b);
  ApiClient.load(this);
  orderId=getIntent().getStringExtra("order_id");
  role=getIntent().getStringExtra("role");if(role==null)role="client";
  LinearLayout root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setBackgroundColor(Ui.BG);
  ScrollView scroll=new ScrollView(this);
  box=Ui.column(this);
  scroll.addView(box);
  root.addView(scroll,new LinearLayout.LayoutParams(-1,0,1));
  footer=new LinearLayout(this);footer.setOrientation(LinearLayout.VERTICAL);footer.setPadding(Ui.dp(this,20),Ui.dp(this,8),Ui.dp(this,20),Ui.dp(this,12));footer.setBackgroundColor(Ui.PANEL);footer.setVisibility(android.view.View.GONE);root.addView(footer,new LinearLayout.LayoutParams(-1,-2));
  setContentView(root);
  load();
 }

 void load(){
  footer.removeAllViews();footer.setVisibility(android.view.View.GONE);
  box.removeAllViews();
  box.addView(Ui.brand(this));
  Button back=Ui.darkButton(this,"← Назад к заявкам");back.setOnClickListener(v->finish());box.addView(back);
  box.addView(Ui.text(this,"Заказ "+orderId,24,true));
  box.addView(Ui.muted(this,"Получаем актуальные данные…",13));
  new Thread(()->{
   try{
    JSONObject r=ApiClient.get("/api/mobile_orders.php");
    if(r.optInt("_http")==401){runOnUiThread(()->{ApiClient.clear(this);startActivity(new Intent(this,LoginActivity.class));finish();});return;}
    if(!r.optBoolean("ok")){String message=ApiClient.errorMessage(r,"Не удалось загрузить заявку");runOnUiThread(()->{box.removeAllViews();box.addView(Ui.brand(this));Button errorBack=Ui.darkButton(this,"← Назад к заявкам");errorBack.setOnClickListener(v->finish());box.addView(errorBack);box.addView(Ui.muted(this,message,14));});return;}
    JSONArray a=r.optJSONArray("orders");
    JSONObject found=null;
    if(a!=null)for(int i=0;i<a.length();i++){
      JSONObject x=a.optJSONObject(i);
      if(x!=null&&orderId.equals(x.optString("id"))){found=x;break;}
    }
    final JSONObject f=found;
    runOnUiThread(()->{order=f;render();});
   }catch(Exception e){
    runOnUiThread(()->{box.addView(Ui.muted(this,"Нет связи с AV Rescue",14));});
   }
  }).start();
 }

 void render(){
  footer.removeAllViews();footer.setVisibility(android.view.View.GONE);
  box.removeAllViews();
  box.addView(Ui.brand(this));
  Button back=Ui.darkButton(this,"← Назад к заявкам");back.setOnClickListener(v->finish());box.addView(back);
  box.addView(Ui.text(this,"Заказ "+orderId,24,true));
  if(order==null){box.addView(Ui.muted(this,"Заказ не найден.",14));return;}

  LinearLayout summary=Ui.card(this);
  summary.addView(Ui.chip(this,statusRu(order.optString("status")),true));
  summary.addView(Ui.text(this,order.optString("service","Услуга"),20,true));
  summary.addView(Ui.routePoint(this,"ТОЧКА А",order.optString("address",""),false));
  boolean detailsLocked=order.optBoolean("details_locked",false);
  if(detailsLocked)summary.addView(Ui.muted(this,"Точка Б, телефон и комментарий откроются после принятия.",13));
  else summary.addView(Ui.routePoint(this,"ТОЧКА Б",order.optString("destination",""),true));
  String vehicle=order.optString("vehicle_type","");
  String plate=order.optString("license_plate",order.optString("car",""));
  String dimensions=order.optDouble("vehicle_weight_kg",0)>0?" • "+Math.round(order.optDouble("vehicle_weight_kg"))+" кг":"";
  if(order.optDouble("vehicle_length_m",0)>0)dimensions+=" • "+order.optDouble("vehicle_length_m")+" м";
  if(!vehicle.isEmpty()||!plate.isEmpty())summary.addView(Ui.muted(this,(vehicle+" • "+plate+dimensions).trim(),14));
  double price=order.optDouble("final_price",order.optDouble("estimated_price",0));
  if(price>0)summary.addView(Ui.text(this,Math.round(price)+" ₽",22,true));
  JSONObject offerFinancials=order.optJSONObject("offer_financials");
  if("executor".equals(role)&&offerFinancials!=null)summary.addView(Ui.muted(this,"Комиссия "+Math.round(offerFinancials.optDouble("commission"))+" ₽ • вам "+Math.round(offerFinancials.optDouble("driver_net"))+" ₽",14));
  if(!detailsLocked){String phone=order.optString("phone","");if(!phone.isEmpty()){Button call=Ui.darkButton(this,"Позвонить клиенту: "+phone);call.setOnClickListener(v->startActivity(new Intent(Intent.ACTION_DIAL,Uri.parse("tel:"+Uri.encode(phone)))));summary.addView(call);}String comment=order.optString("comment","");if(!comment.isEmpty())summary.addView(Ui.muted(this,"Комментарий: "+comment,13));}
  if(order.optBoolean("loading_complexity",false))summary.addView(Ui.chip(this,"Сложная погрузка учтена",true));
  if(order.optInt("dollies",0)>0)summary.addView(Ui.chip(this,"Подкаты: "+order.optInt("dollies"),true));
  if(order.optBoolean("dead_battery",false))summary.addView(Ui.chip(this,"Севший АКБ учтён",true));
  String currentStatus=order.optString("status","");int eta=order.optInt("estimated_arrival_min",0);
  if(eta>0&&!"done".equals(currentStatus)&&!"completed".equals(currentStatus)&&!"cancelled".equals(currentStatus)){String etaTitle="in_transit".equals(currentStatus)?"До адреса назначения ≈ ":"До адреса подачи ≈ ";summary.addView(Ui.chip(this,etaTitle+eta+" мин",true));}
  box.addView(summary);
  if("executor".equals(role))box.addView(Ui.workProgress(this,currentStatus));
  String payStatus=order.optString("payment_status","unpaid");boolean payable=price>0&&!"paid".equals(payStatus)&&!"refunded".equals(payStatus)&&!"cancelled".equals(currentStatus);
  if(payable&&"executor".equals(role)&&order.optString("user_id","").isEmpty()){LinearLayout payment=Ui.card(this);payment.addView(Ui.text(this,"Безналичная оплата",18,true));payment.addView(Ui.muted(this,"Откройте банковский QR и передайте экран клиенту.",13));Button qr=Ui.button(this,"Показать QR для оплаты");qr.setOnClickListener(v->createPayment(qr,true));payment.addView(qr);box.addView(payment);}

  String worker=order.optString("assigned_worker_name","");
  if(!"executor".equals(role)&&!worker.isEmpty()){
    LinearLayout w=Ui.card(this);
    w.addView(Ui.text(this,"Исполнитель",18,true));
    w.addView(Ui.text(this,worker,16,true));double wr=order.optDouble("worker_rating",0);if(wr>0)w.addView(Ui.muted(this,"Рейтинг "+wr,14));
    box.addView(w);
  }

  boolean toDestination="in_transit".equals(currentStatus);double lat=toDestination?order.optDouble("destination_lat",0):order.optDouble("lat",0), lng=toDestination?order.optDouble("destination_lng",0):order.optDouble("lng",0);String navAddress=toDestination?order.optString("destination",""):order.optString("address","");
  boolean mapAllowed=!"executor".equals(role)||order.optBoolean("allow_map_navigation",false);
  if(mapAllowed&&((lat!=0&&lng!=0)||!navAddress.isEmpty())){
    Button nav=Ui.darkButton(this,toDestination?"Маршрут к точке Б":"Маршрут к точке А");
    nav.setOnClickListener(v->{if(toDestination)openDestinationMap(lat,lng,navAddress);else openYandexMap(lat,lng,navAddress);});
    box.addView(nav);
  }

  // Executor controls appear only if backend confirms the order belongs to executor.
  String assigned=order.optString("assigned_worker_id","");
  if("executor".equals(role)&&!assigned.isEmpty()&&!"done".equals(currentStatus)&&!"completed".equals(currentStatus)&&!"cancelled".equals(currentStatus)){
    LinearLayout actions=Ui.card(this);
    actions.addView(Ui.text(this,"Следующий шаг",18,true));
    actions.addView(Ui.muted(this,"Основная рабочая кнопка всегда доступна внизу экрана.",13));
    String st=order.optString("status","");
    if(st.equals("offered")){
      setPrimaryAction("Принять заказ",()->action("accept"));
      Button reject=Ui.darkButton(this,"Отказаться");reject.setOnClickListener(v->action("reject"));actions.addView(reject);
    } else if(st.equals("assigned")){
      setPrimaryAction("Начать маршрут к точке А",()->action("enroute"));
    } else if(st.equals("enroute")){
      setPrimaryAction("Я на месте",()->action("arrived"));
    } else if(st.equals("arrived")){
      JSONObject pending=order.optJSONObject("price_adjustment_pending");boolean approvalPending=pending!=null&&"pending".equals(pending.optString("status"));
      if(approvalPending)actions.addView(Ui.muted(this,"Ожидается подтверждение клиента: "+Math.round(pending.optDouble("before"))+" → "+Math.round(pending.optDouble("after"))+" ₽. До решения погрузка недоступна.",14));
      boolean complex=order.optBoolean("loading_complexity",false);
      Button complexity=Ui.darkButton(this,complex?"Убрать сложную погрузку":"Добавить сложную погрузку");complexity.setEnabled(!approvalPending);complexity.setOnClickListener(v->confirmLoadingComplexity(!complex));actions.addView(complexity);
      int dollies=approvalPending?pending.optInt("dollies",0):order.optInt("dollies",0);Button dolliesButton=Ui.darkButton(this,"Подкаты: "+dollies+" — изменить");dolliesButton.setEnabled(!approvalPending);dolliesButton.setOnClickListener(v->chooseDollies(dollies));actions.addView(dolliesButton);
      boolean deadBattery=approvalPending?pending.optBoolean("dead_battery",false):order.optBoolean("dead_battery",false);Button battery=Ui.darkButton(this,deadBattery?"Убрать бустер АКБ":"Добавить бустер АКБ");battery.setEnabled(!approvalPending);battery.setOnClickListener(v->confirmExtra("dead_battery",!deadBattery,"Бустер АКБ"));actions.addView(battery);
      if(approvalPending){Button loading=Ui.darkButton(this,"Ожидается решение клиента");loading.setEnabled(false);actions.addView(loading);}else setPrimaryAction("Фото автомобиля и погрузка",()->takeLoadingPhoto());
    } else if(st.equals("loading")){
      setPrimaryAction("Погрузка завершена — к точке Б",()->action("in_transit"));
    } else if(st.equals("in_transit")){
      setPrimaryAction("Завершить заявку",()->action("done"));
    }
    box.addView(actions);
  }

  if("superadmin".equals(role)||"admin".equals(role)||"dispatcher".equals(role)||"staff".equals(role)){
    LinearLayout staff=Ui.card(this);staff.addView(Ui.text(this,"Управление диспетчерской",18,true));
    Button auto=Ui.button(this,"Назначить ближайшего автоматически");auto.setOnClickListener(v->autoDispatch());staff.addView(auto);Button search=Ui.darkButton(this,"Перевести в поиск исполнителя");search.setOnClickListener(v->action("searching"));staff.addView(search);
    Button reopen=Ui.darkButton(this,"Вернуть в новые");reopen.setOnClickListener(v->action("reopen"));staff.addView(reopen);
    Button cancel=Ui.darkButton(this,"Отменить заказ");cancel.setOnClickListener(v->action("cancel"));staff.addView(cancel);box.addView(staff);
  }

  if(("client".equals(role)||"b2b".equals(role))){JSONObject pending=order.optJSONObject("price_adjustment_pending");if(pending!=null&&"pending".equals(pending.optString("status"))){LinearLayout approval=Ui.card(this);approval.addView(Ui.text(this,"Согласование доплаты",18,true));String details="Подкаты: "+pending.optInt("dollies",0)+" шт."+(pending.optBoolean("dead_battery",false)?" • бустер АКБ":"")+"\n"+Math.round(pending.optDouble("before"))+" → "+Math.round(pending.optDouble("after"))+" ₽";approval.addView(Ui.muted(this,details,14));Button approve=Ui.button(this,"Подтвердить новую стоимость");approve.setOnClickListener(v->decideAdjustment("approve",pending.optString("id")));approval.addView(approve);Button reject=Ui.darkButton(this,"Отклонить доплату");reject.setOnClickListener(v->decideAdjustment("reject",pending.optString("id")));approval.addView(reject);box.addView(approval);}}
  if("client".equals(role)&&!"done".equals(order.optString("status"))&&!"completed".equals(order.optString("status"))&&!"cancelled".equals(order.optString("status"))){Button cancel=Ui.darkButton(this,"Отменить заказ");cancel.setOnClickListener(v->action("client_cancel"));box.addView(cancel);}
  if(("done".equals(order.optString("status"))||"completed".equals(order.optString("status"))) && !("executor".equals(role))){LinearLayout rate=Ui.card(this);rate.addView(Ui.text(this,"Оценить исполнителя",18,true));LinearLayout stars=new LinearLayout(this);for(int i=1;i<=5;i++){final int score=i;Button b=new Button(this);b.setText(String.valueOf(i)+"★");b.setOnClickListener(v->rate(score));stars.addView(b,new LinearLayout.LayoutParams(0,-2,1));}rate.addView(stars);box.addView(rate);}
  if(!"done".equals(currentStatus)&&!"completed".equals(currentStatus)&&!"cancelled".equals(currentStatus)){Button tracking=Ui.darkButton(this,"Обновить отслеживание");tracking.setOnClickListener(v->tracking());box.addView(tracking);}
  Button refresh=Ui.darkButton(this,"Обновить заказ");refresh.setOnClickListener(v->load());box.addView(refresh);
 }

 void setPrimaryAction(String label,Runnable action){footer.removeAllViews();Button button=Ui.primaryButton(this,label);button.setOnClickListener(v->action.run());footer.addView(button);footer.setVisibility(android.view.View.VISIBLE);}

 void openYandexMap(double lat,double lng,String address){
  Uri uri=(lat!=0&&lng!=0)?Uri.parse("yandexnavi://build_route_on_map?lat_to="+lat+"&lon_to="+lng):Uri.parse("https://yandex.ru/maps/?text="+Uri.encode(address));
  Intent intent=new Intent(Intent.ACTION_VIEW,uri);intent.setPackage("ru.yandex.yandexnavi");
  try{startActivity(intent);}catch(Exception e){Uri web=(lat!=0&&lng!=0)?Uri.parse("https://yandex.ru/maps/?ll="+lng+"%2C"+lat+"&z=16&pt="+lng+"%2C"+lat+"%2Cpm2rdm"):Uri.parse("https://yandex.ru/maps/?text="+Uri.encode(address));try{startActivity(new Intent(Intent.ACTION_VIEW,web));}catch(Exception ignored){Toast.makeText(this,"Не удалось открыть Яндекс Карты",Toast.LENGTH_SHORT).show();}}
 }
 void openDestinationMap(double lat,double lng,String address){String exact=address==null?"":address.trim();if(exact.isEmpty()){openYandexMap(lat,lng,exact);return;}Uri route=Uri.parse("yandexmaps://maps.yandex.ru/?rtext=~"+Uri.encode(exact)+"&rtt=auto");Intent intent=new Intent(Intent.ACTION_VIEW,route);intent.setPackage("ru.yandex.yandexmaps");try{startActivity(intent);}catch(Exception e){Uri web=Uri.parse("https://yandex.ru/maps/?rtext=~"+Uri.encode(exact)+"&rtt=auto");try{startActivity(new Intent(Intent.ACTION_VIEW,web));}catch(Exception ignored){Toast.makeText(this,"Не удалось открыть адрес точки Б",Toast.LENGTH_SHORT).show();}}}
 void createPayment(Button button,boolean driverQr){button.setEnabled(false);button.setText("Создаём оплату…");new Thread(()->{try{JSONObject r=ApiClient.post("/api/payment_create.php",new JSONObject().put("order_id",orderId));runOnUiThread(()->{button.setEnabled(true);button.setText("Показать QR для оплаты");if(r.optBoolean("ok")){Intent in=new Intent(this,PaymentActivity.class);in.putExtra("payment_url",r.optString("payment_url"));in.putExtra("driver_qr",driverQr);startActivity(in);}else Toast.makeText(this,r.optString("error","Не удалось создать оплату"),Toast.LENGTH_LONG).show();});}catch(Exception e){runOnUiThread(()->{button.setEnabled(true);button.setText("Показать QR для оплаты");Toast.makeText(this,"Нет связи с AV Rescue",Toast.LENGTH_LONG).show();});}}).start();}


 void autoDispatch(){new Thread(()->{try{JSONObject r=ApiClient.post("/api/mobile_auto_dispatch.php",new JSONObject().put("order_id",orderId));runOnUiThread(()->{Toast.makeText(this,r.optBoolean("assigned")?"Ближайший водитель назначен":"Свободных водителей пока нет",Toast.LENGTH_LONG).show();load();});}catch(Exception e){runOnUiThread(()->Toast.makeText(this,"Нет связи",Toast.LENGTH_SHORT).show());}}).start();}
 void tracking(){new Thread(()->{try{JSONObject r=ApiClient.get("/api/mobile_tracking.php?order_id="+orderId);runOnUiThread(()->{if(r.optBoolean("ok")){JSONObject w=r.optJSONObject("worker");String msg="Статус: "+statusRu(r.optString("status"));if(w!=null)msg+="\nВодитель: "+w.optString("name")+" • рейтинг "+w.optDouble("rating",5);if(r.optInt("eta_min",0)>0)msg+="\nETA: ≈ "+r.optInt("eta_min")+" мин";new AlertDialog.Builder(this).setTitle("Отслеживание").setMessage(msg).setPositiveButton("OK",null).show();}});}catch(Exception e){}}).start();}
 void rate(int score){new Thread(()->{try{JSONObject r=ApiClient.post("/api/mobile_rate.php",new JSONObject().put("order_id",orderId).put("score",score));runOnUiThread(()->Toast.makeText(this,r.optBoolean("ok")?"Спасибо за оценку":"Оценка не сохранена",Toast.LENGTH_SHORT).show());}catch(Exception e){}}).start();}
 void confirmLoadingComplexity(boolean enabled){
  String message=enabled?"После осмотра добавьте услугу. Зарегистрированный клиент получит запрос на подтверждение, для гостевого заказа сумма применится сразу.":"Убрать ранее добавленную сложность погрузки из расчёта?";
  new AlertDialog.Builder(this).setTitle(enabled?"Сложная погрузка":"Исправить расчёт").setMessage(message).setNegativeButton("Отмена",null).setPositiveButton(enabled?"Добавить":"Убрать",(d,w)->setLoadingComplexity(enabled)).show();
 }
 void setLoadingComplexity(boolean enabled){new Thread(()->{try{JSONObject payload=new JSONObject().put("order_id",orderId).put("field","loading_complexity").put("enabled",enabled);JSONObject r=ApiClient.post("/api/mobile_order_adjustment.php",payload);runOnUiThread(()->{if(r.optBoolean("ok")){Toast.makeText(this,r.optBoolean("approval_required")?"Запрос отправлен клиенту":"Стоимость заказа обновлена",Toast.LENGTH_LONG).show();load();}else Toast.makeText(this,r.optString("error","Не удалось изменить стоимость"),Toast.LENGTH_LONG).show();});}catch(Exception e){runOnUiThread(()->Toast.makeText(this,"Нет связи с AV Rescue",Toast.LENGTH_LONG).show());}}).start();}
 void confirmExtra(String field,boolean enabled,String title){String message=enabled?"Зарегистрированный клиент получит запрос на подтверждение. Для гостевого заказа новая сумма применится сразу.":"Убрать эту доплату из расчёта?";new AlertDialog.Builder(this).setTitle(title).setMessage(message).setNegativeButton("Отмена",null).setPositiveButton(enabled?"Добавить":"Убрать",(d,w)->setExtra(field,enabled?1:0)).show();}
 void chooseDollies(int current){String[] values={"Без подкатов","1 подкат","2 подката","3 подката","4 подката"};new AlertDialog.Builder(this).setTitle("Подкаты").setSingleChoiceItems(values,Math.max(0,Math.min(4,current)),(dialog,which)->{dialog.dismiss();if(which==current)return;new AlertDialog.Builder(this).setTitle("Изменить количество подкатов").setMessage("Зарегистрированный клиент получит запрос на подтверждение. Для гостевого заказа новая сумма применится сразу.").setNegativeButton("Отмена",null).setPositiveButton("Продолжить",(d,w)->setExtra("dollies",which)).show();}).show();}
 void setExtra(String field,int value){new Thread(()->{try{JSONObject payload=new JSONObject().put("order_id",orderId).put("field",field).put("value",value).put("enabled",value>0);JSONObject r=ApiClient.post("/api/mobile_order_adjustment.php",payload);runOnUiThread(()->{if(r.optBoolean("ok")){Toast.makeText(this,r.optBoolean("approval_required")?"Запрос отправлен клиенту":"Стоимость заказа обновлена",Toast.LENGTH_LONG).show();load();}else Toast.makeText(this,r.optString("error","Не удалось изменить стоимость"),Toast.LENGTH_LONG).show();});}catch(Exception e){runOnUiThread(()->Toast.makeText(this,"Нет связи с AV Rescue",Toast.LENGTH_LONG).show());}}).start();}
 void decideAdjustment(String decision,String requestId){new Thread(()->{try{JSONObject r=ApiClient.post("/api/client_order_adjustment.php",new JSONObject().put("order_id",orderId).put("action",decision).put("request_id",requestId));runOnUiThread(()->{Toast.makeText(this,r.optBoolean("ok")?("approve".equals(decision)?"Новая стоимость подтверждена":"Доплата отклонена"):r.optString("error","Не удалось сохранить решение"),Toast.LENGTH_LONG).show();if(r.optBoolean("ok"))load();});}catch(Exception e){runOnUiThread(()->Toast.makeText(this,"Нет связи с AV Rescue",Toast.LENGTH_LONG).show());}}).start();}
 void takeLoadingPhoto(){if(photoStep>=4)return;new AlertDialog.Builder(this).setTitle("Фото "+(photoStep+1)+" из 4").setMessage("Сфотографируйте автомобиль "+photoLabels[photoStep]).setNegativeButton("Отмена",null).setPositiveButton("Открыть камеру",(d,w)->{Intent camera=new Intent(MediaStore.ACTION_IMAGE_CAPTURE);try{startActivityForResult(camera,PHOTO_BEFORE_LOADING);}catch(Exception e){Toast.makeText(this,"Камера недоступна",Toast.LENGTH_LONG).show();}}).show();}
 protected void onActivityResult(int requestCode,int resultCode,Intent data){super.onActivityResult(requestCode,resultCode,data);if(requestCode!=PHOTO_BEFORE_LOADING||resultCode!=RESULT_OK||data==null)return;Object raw=data.getExtras()==null?null:data.getExtras().get("data");if(!(raw instanceof Bitmap)){Toast.makeText(this,"Фото не получено",Toast.LENGTH_LONG).show();return;}Bitmap photo=(Bitmap)raw;ByteArrayOutputStream out=new ByteArrayOutputStream();photo.compress(Bitmap.CompressFormat.JPEG,90,out);String encoded=Base64.encodeToString(out.toByteArray(),Base64.NO_WRAP);int step=photoStep;Toast.makeText(this,"Сохраняем фото "+(step+1)+" из 4…",Toast.LENGTH_SHORT).show();new Thread(()->{try{JSONObject upload=ApiClient.post("/api/mobile_upload.php",new JSONObject().put("kind",photoKinds[step]).put("order_id",orderId).put("data",encoded));if(!upload.optBoolean("ok")){runOnUiThread(()->Toast.makeText(this,upload.optString("error","Фото не сохранено"),Toast.LENGTH_LONG).show());return;}photoPaths[step]=upload.optString("path");photoStep=step+1;runOnUiThread(()->{if(photoStep<4){Toast.makeText(this,"Фото "+photoStep+" из 4 сохранено",Toast.LENGTH_SHORT).show();takeLoadingPhoto();}else startLoadingWithPhotos();});}catch(Exception e){runOnUiThread(()->Toast.makeText(this,"Нет связи с AV Rescue",Toast.LENGTH_LONG).show());}}).start();}
 void startLoadingWithPhotos(){new Thread(()->{try{JSONArray photos=new JSONArray();for(int i=0;i<4;i++)photos.put(new JSONObject().put("side",photoKinds[i]).put("path",photoPaths[i]));JSONObject result=ApiClient.post("/api/mobile_order_action.php",new JSONObject().put("order_id",orderId).put("action","loading").put("photos",photos));runOnUiThread(()->{if(result.optBoolean("ok")){Toast.makeText(this,"4 фото сохранены. Погрузка начата",Toast.LENGTH_LONG).show();load();}else Toast.makeText(this,result.optString("error","Погрузка не начата"),Toast.LENGTH_LONG).show();});}catch(Exception e){runOnUiThread(()->Toast.makeText(this,"Нет связи с AV Rescue",Toast.LENGTH_LONG).show());}}).start();}
 void action(String action){
  if("executor".equals(role)&&("accept".equals(action)||"reject".equals(action))){Intent stopAlert=new Intent(this,DriverLocationService.class).setAction(DriverLocationService.ACTION_STOP_ALERT);if(Build.VERSION.SDK_INT>=26)startForegroundService(stopAlert);else startService(stopAlert);}
  new Thread(()->{
   try{
    JSONObject r=ApiClient.post("/api/mobile_order_action.php",new JSONObject().put("order_id",orderId).put("action",action));
    runOnUiThread(()->{
      if(r.optBoolean("ok")){if("done".equals(action)&&"executor".equals(role)){Toast.makeText(this,"Заказ завершён. Вы остались на линии",Toast.LENGTH_LONG).show();Intent in=new Intent(this,DashboardActivity.class);in.putExtra("open_line",true);in.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK|Intent.FLAG_ACTIVITY_CLEAR_TASK);startActivity(in);finish();}else{Toast.makeText(this,"Статус обновлён",Toast.LENGTH_SHORT).show();load();}}
      else Toast.makeText(this,ApiClient.errorMessage(r,"Не удалось изменить статус заявки"),Toast.LENGTH_LONG).show();
    });
   }catch(Exception e){runOnUiThread(()->Toast.makeText(this,"Нет связи",Toast.LENGTH_SHORT).show());}
  }).start();
 }

 String statusRu(String s){
  if("new".equals(s))return "● Новая заявка";
  if("searching".equals(s))return "● Ищем исполнителя";
  if("offered".equals(s))return "● Предложен заказ";
  if("assigned".equals(s))return "● Заявка назначена вам";
  if("enroute".equals(s))return "● Вы едете к точке А";
  if("arrived".equals(s))return "● Вы на месте";
  if("loading".equals(s))return "● Идёт погрузка";
  if("in_transit".equals(s))return "● Вы едете к точке Б";
  if("done".equals(s)||"completed".equals(s))return "● Заказ завершён";
  if("cancelled".equals(s))return "● Заказ отменён";
  return "● "+s;
 }
}
