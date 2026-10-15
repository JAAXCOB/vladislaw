package ru.avrescue.app;

import android.app.Activity;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.WindowManager;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.LinearLayout;

public class PaymentActivity extends Activity {
 private static boolean trustedPaymentUrl(String value){
  if(value==null)return false;
  Uri uri=Uri.parse(value);String host=uri.getHost();
  if(!"https".equalsIgnoreCase(uri.getScheme())||host==null)return false;
  host=host.toLowerCase(java.util.Locale.ROOT);
  return host.equals("av-rescue.ru")||host.endsWith(".av-rescue.ru")||host.equals("alfabank.ru")||host.endsWith(".alfabank.ru")||host.equals("rbsuat.com")||host.endsWith(".rbsuat.com")||host.equals("payment2way.com")||host.endsWith(".payment2way.com");
 }
 public void onCreate(Bundle state){
  super.onCreate(state);getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);
  String url=getIntent().getStringExtra("payment_url");boolean qr=getIntent().getBooleanExtra("driver_qr",false);
  LinearLayout root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setBackgroundColor(Ui.BG);
  root.addView(Ui.text(this,qr?"QR для оплаты":"Оплата заказа",24,true));
  root.addView(Ui.muted(this,qr?"Передайте телефон клиенту: он сканирует QR в банковском приложении.":"Защищённая страница банка. AV Rescue не получает данные карты.",13));
  if(!trustedPaymentUrl(url)){root.addView(Ui.muted(this,"Платёжная ссылка заблокирована проверкой безопасности.",15));setContentView(root);return;}
  WebView web=new WebView(this);WebSettings settings=web.getSettings();settings.setJavaScriptEnabled(true);settings.setDomStorageEnabled(true);settings.setAllowFileAccess(false);settings.setAllowContentAccess(false);settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);if(Build.VERSION.SDK_INT>=26)settings.setSafeBrowsingEnabled(true);
  web.setWebViewClient(new WebViewClient(){public boolean shouldOverrideUrlLoading(WebView view,WebResourceRequest request){String next=request.getUrl().toString();if(next.startsWith("https://")){view.loadUrl(next);}return true;}});
  root.addView(web,new LinearLayout.LayoutParams(-1,0,1));setContentView(root);web.loadUrl(url);
 }
}
