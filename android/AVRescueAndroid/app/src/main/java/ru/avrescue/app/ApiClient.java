package ru.avrescue.app;

import android.content.Context;
import android.content.SharedPreferences;
import org.json.JSONObject;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;

public final class ApiClient {
    public static final String BASE="https://av-rescue.ru";
    private static String token="";
    private ApiClient(){}

    public static void load(Context c){
        if(token==null || token.isEmpty()){
            token=c.getSharedPreferences("avr",Context.MODE_PRIVATE).getString("token","");
        }
    }

    public static void saveToken(Context c,String t){
        token=(t==null?"":t);
        SharedPreferences.Editor e=c.getSharedPreferences("avr",Context.MODE_PRIVATE)
                .edit()
                .putString("token",token);
        if(!e.commit()){
            throw new IllegalStateException("Не удалось сохранить токен AV Rescue");
        }
    }

    public static void clear(Context c){
        token="";
        c.getSharedPreferences("avr",Context.MODE_PRIVATE).edit().remove("token").commit();
    }

    public static boolean hasToken(){
        return token!=null&&!token.isEmpty();
    }

    public static JSONObject get(String path) throws Exception {
        return request("GET",path,null);
    }

    public static JSONObject post(String path,JSONObject body) throws Exception {
        return request("POST",path,body);
    }

    public static String errorMessage(JSONObject response,String fallback){
        if(response==null)return fallback;
        String error=response.optString("error","").trim();
        if(error.isEmpty())return fallback;
        if("AUTH_REQUIRED".equals(error))return "Сессия завершена. Войдите снова";
        if("EXECUTOR_REQUIRED".equals(error))return "Этот аккаунт не является аккаунтом водителя";
        if("MODERATION_REQUIRED".equals(error))return "Профиль водителя ещё проверяется";
        if("WORKER_BUSY".equals(error))return "Сначала завершите текущую заявку";
        if("NEXT_ORDER_ALREADY_QUEUED".equals(error))return "Следующая заявка уже назначена";
        if("ORDER_NOT_FOUND".equals(error))return "Заявка больше недоступна";
        if("INTERNAL_SERVER_ERROR".equals(error)||error.startsWith("HTTP 5"))return "Сервер временно недоступен. Повторите позже";
        if(!error.matches("[A-Z0-9_]+"))return error;
        if(response.optInt("_http")==401)return "Логин или пароль не подошли";
        if(response.optInt("_http")==403)return "Доступ к приложению водителя не подтверждён";
        return fallback;
    }

    private static JSONObject request(String method,String path,JSONObject body) throws Exception {
        HttpURLConnection c=(HttpURLConnection)new URL(BASE+path).openConnection();
        c.setRequestMethod(method);
        c.setConnectTimeout(12000);
        c.setReadTimeout(15000);
        c.setRequestProperty("Accept","application/json");

        if(hasToken()){
            c.setRequestProperty("Authorization","Bearer "+token);
            // Fallback for hosting/proxies that do not pass Authorization to PHP.
            c.setRequestProperty("X-AVR-Token",token);
        }

        if(body!=null){
            c.setDoOutput(true);
            c.setRequestProperty("Content-Type","application/json; charset=utf-8");
            try(OutputStream os=c.getOutputStream()){
                os.write(body.toString().getBytes(StandardCharsets.UTF_8));
            }
        }

        int code=c.getResponseCode();
        InputStream is=(code>=200&&code<400)?c.getInputStream():c.getErrorStream();

        StringBuilder sb=new StringBuilder();
        if(is!=null){
            try(BufferedReader br=new BufferedReader(new InputStreamReader(is,StandardCharsets.UTF_8))){
                String line;
                while((line=br.readLine())!=null)sb.append(line);
            }
        }

        JSONObject j;
        try{
            j=new JSONObject(sb.toString());
        }catch(Exception e){
            j=new JSONObject();
            j.put("ok",false);
            j.put("error","HTTP "+code+": "+sb);
        }
        j.put("_http",code);
        return j;
    }
}
