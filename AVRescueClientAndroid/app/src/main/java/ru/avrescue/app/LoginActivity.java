package ru.avrescue.app;
import android.app.*;import android.os.*;import android.content.*;import android.net.Uri;import android.text.InputType;import android.widget.*;import org.json.JSONObject;
public class LoginActivity extends Activity {
 protected void onStart(){super.onStart();getWindow().addFlags(android.view.WindowManager.LayoutParams.FLAG_SECURE);}
 EditText login,pass;TextView status;
 public void onCreate(Bundle b){super.onCreate(b);ApiClient.load(this);if(ApiClient.hasToken()){open();return;}
  LinearLayout page=Ui.column(this);page.addView(Ui.brand(this));
  LinearLayout card=Ui.card(this);card.addView(Ui.text(this,"AV Rescue",24,true));
  login=Ui.input(this,"Логин или email");pass=Ui.input(this,"Пароль");pass.setInputType(InputType.TYPE_CLASS_TEXT|InputType.TYPE_TEXT_VARIATION_PASSWORD);
  Button go=Ui.button(this,"Войти");Button forgot=Ui.darkButton(this,"Забыли пароль?");status=Ui.muted(this,"",14);card.addView(login);card.addView(pass);card.addView(go);card.addView(forgot);
  Button reg=Ui.darkButton(this,"Регистрация клиента");Button b2b=Ui.darkButton(this,"B2B-партнёрам");card.addView(reg);card.addView(b2b);card.addView(status);page.addView(card);
  forgot.setOnClickListener(v->startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse("https://av-rescue.ru/forgot-password.php"))));reg.setOnClickListener(v->openRegister("client"));b2b.setOnClickListener(v->openRegister("b2b"));setContentView(page);go.setOnClickListener(v->doLogin());
 }
 void openRegister(String type){Intent i=new Intent(this,RegisterActivity.class);i.putExtra("type",type);startActivity(i);}
 void doLogin(){status.setText("Проверяем аккаунт…");new Thread(()->{try{JSONObject r=ApiClient.post("/api/mobile_login.php",new JSONObject().put("login",login.getText().toString()).put("password",pass.getText().toString()).put("app_mode","client"));runOnUiThread(()->{if(r.optBoolean("ok")){ApiClient.saveToken(this,r.optString("token"));open();}else if(r.optBoolean("verification_required")){Intent i=new Intent(this,VerificationActivity.class);i.putExtra("account_type",r.optString("account_type"));i.putExtra("id",r.optString("id"));i.putExtra("verification_token",r.optString("verification_token"));startActivity(i);}else status.setText(r.optString("error","Ошибка входа"));});}catch(Exception e){runOnUiThread(()->status.setText("Нет связи с AV Rescue"));}}).start();}
 void open(){startActivity(new Intent(this,DashboardActivity.class));finish();}
}
