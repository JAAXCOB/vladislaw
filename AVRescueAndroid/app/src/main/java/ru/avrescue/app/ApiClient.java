package ru.avrescue.app;

import android.content.Context;
import android.content.Intent;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import org.json.JSONObject;
import java.io.*;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.atomic.AtomicBoolean;
import javax.net.ssl.HttpsURLConnection;

public final class ApiClient {
    public static final String BASE="https://av-rescue.ru";
    private static final int MAX_RESPONSE_BYTES=1024*1024;
    private static final AtomicBoolean redirectingToLogin=new AtomicBoolean(false);
    private static volatile String token="";
    private static volatile Context appContext;
    private static int transportFailures=0;
    private static long retryAfterElapsed=0;
    private ApiClient(){}

    public static synchronized void load(Context context){
        appContext=context.getApplicationContext();
        if(token==null||token.isEmpty()){
            token=SecureStore.get(appContext,"session_token");
            String legacy=appContext.getSharedPreferences("avr",Context.MODE_PRIVATE).getString("token","");
            if(token.isEmpty()&&!legacy.isEmpty()){SecureStore.put(appContext,"session_token",legacy);token=legacy;}
            appContext.getSharedPreferences("avr",Context.MODE_PRIVATE).edit().remove("token").commit();
        }
    }

    public static synchronized void saveToken(Context context,String value){load(context);token=value==null?"":value;if(token.isEmpty())SecureStore.remove(appContext,"session_token");else SecureStore.put(appContext,"session_token",token);redirectingToLogin.set(false);}
    public static synchronized void clear(Context context){appContext=context.getApplicationContext();clearStoredToken();redirectingToLogin.set(false);}
    private static synchronized void clearStoredToken(){token="";if(appContext!=null){SecureStore.remove(appContext,"session_token");appContext.getSharedPreferences("avr",Context.MODE_PRIVATE).edit().remove("token").remove("last_lat").remove("last_lng").remove("last_fix_time").remove("last_accuracy").commit();}}
    public static boolean hasToken(){return token!=null&&!token.isEmpty();}
    public static JSONObject get(String path)throws Exception{return request("GET",path,null);}
    public static JSONObject post(String path,JSONObject body)throws Exception{return request("POST",path,body);}

    private static JSONObject request(String method,String path,JSONObject body)throws Exception{
        if(path==null||!path.startsWith("/")||path.startsWith("//"))throw new IllegalArgumentException("Invalid API path");
        if(SystemClock.elapsedRealtime()<retryAfterElapsed)throw new IOException("API temporarily unavailable");
        HttpsURLConnection connection=(HttpsURLConnection)new URL(BASE+path).openConnection();
        try{
            connection.setRequestMethod(method);connection.setConnectTimeout(12000);connection.setReadTimeout(15000);connection.setRequestProperty("Accept","application/json");connection.setUseCaches(false);
            if(hasToken())connection.setRequestProperty("Authorization","Bearer "+token);
            if(body!=null){connection.setDoOutput(true);connection.setRequestProperty("Content-Type","application/json; charset=utf-8");try(OutputStream os=connection.getOutputStream()){os.write(body.toString().getBytes(StandardCharsets.UTF_8));}}
            int code=connection.getResponseCode();InputStream stream=(code>=200&&code<400)?connection.getInputStream():connection.getErrorStream();String raw=readLimited(stream);
            JSONObject result;try{result=raw.isEmpty()?new JSONObject():new JSONObject(raw);}catch(Exception malformed){result=new JSONObject().put("ok",false).put("error","Сервер вернул некорректный ответ");}
            result.put("_http",code);if(code==401)expireSession();if(code>=500)recordFailure();else recordSuccess();return result;
        }catch(IOException e){recordFailure();throw e;}finally{connection.disconnect();}
    }

    private static String readLimited(InputStream stream)throws IOException{if(stream==null)return "";ByteArrayOutputStream output=new ByteArrayOutputStream();byte[] buffer=new byte[8192];int total=0,n;try(InputStream input=stream){while((n=input.read(buffer))!=-1){total+=n;if(total>MAX_RESPONSE_BYTES)throw new IOException("API response is too large");output.write(buffer,0,n);}}return output.toString(StandardCharsets.UTF_8.name());}
    private static synchronized void recordSuccess(){transportFailures=0;retryAfterElapsed=0;}
    private static synchronized void recordFailure(){transportFailures=Math.min(5,transportFailures+1);retryAfterElapsed=SystemClock.elapsedRealtime()+Math.min(30000L,1000L<<(transportFailures-1));}
    private static void expireSession(){if(!redirectingToLogin.compareAndSet(false,true))return;clearStoredToken();Context context=appContext;if(context==null)return;context.stopService(new Intent(context,DriverLocationService.class));new Handler(Looper.getMainLooper()).post(()->{Intent intent=new Intent(context,LoginActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK|Intent.FLAG_ACTIVITY_CLEAR_TASK);context.startActivity(intent);});}
}
