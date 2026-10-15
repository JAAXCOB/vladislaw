package ru.avrescue.app;
import android.app.*;import android.os.*;import android.content.*;import android.text.InputType;import android.widget.*;import org.json.JSONObject;
public class LoginActivity extends Activity {
 EditText login,pass;TextView status;
 public void onCreate(Bundle b){super.onCreate(b);migrateFromUnifiedApp();ApiClient.load(this);if(ApiClient.hasToken()){open();return;}
  LinearLayout page=Ui.column(this);page.addView(Ui.brand(this));
  LinearLayout card=Ui.card(this);card.addView(Ui.text(this,"AV Rescue Driver",24,true));
  login=Ui.input(this,"Логин или email");pass=Ui.input(this,"Пароль");pass.setInputType(InputType.TYPE_CLASS_TEXT|InputType.TYPE_TEXT_VARIATION_PASSWORD);
  Button go=Ui.button(this,"Войти");status=Ui.muted(this,"",14);card.addView(login);card.addView(pass);card.addView(go);
  Button exec=Ui.darkButton(this,"Стать исполнителем");card.addView(exec);card.addView(status);page.addView(card);
  exec.setOnClickListener(v->openRegister("executor"));setContentView(page);go.setOnClickListener(v->doLogin());
 }
 void openRegister(String type){Intent i=new Intent(this,RegisterActivity.class);i.putExtra("type",type);startActivity(i);}
 void migrateFromUnifiedApp(){android.content.SharedPreferences p=getSharedPreferences("avr",MODE_PRIVATE);if(!p.getBoolean("driver_split_migrated",false)){p.edit().remove("token").putBoolean("driver_split_migrated",true).commit();}}
 void doLogin(){String username=login.getText().toString().trim();String password=pass.getText().toString();if(username.isEmpty()||password.isEmpty()){status.setText("Введите логин и пароль");return;}status.setText("Проверяем аккаунт…");new Thread(()->{try{JSONObject r=ApiClient.post("/api/mobile_login.php",new JSONObject().put("login",username).put("password",password).put("app_mode","driver"));runOnUiThread(()->{if(r.optBoolean("ok")){ApiClient.saveToken(this,r.optString("token"));open();}else if(r.optBoolean("verification_required")){Intent i=new Intent(this,VerificationActivity.class);i.putExtra("account_type",r.optString("account_type"));i.putExtra("id",r.optString("id"));i.putExtra("verification_token",r.optString("verification_token"));startActivity(i);}else status.setText(ApiClient.errorMessage(r,"Не удалось войти. Проверьте логин и пароль"));});}catch(Exception e){runOnUiThread(()->status.setText("Нет связи с AV Rescue"));}}).start();}
 void open(){startActivity(new Intent(this,DashboardActivity.class));finish();}
}
