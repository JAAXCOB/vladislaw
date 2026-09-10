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

    public static byte[] getBytes(String path) throws Exception {
        HttpURLConnection c=(HttpURLConnection)new URL(BASE+path).openConnection();
        c.setRequestMethod("GET");c.setConnectTimeout(12000);c.setReadTimeout(15000);
        if(hasToken()){c.setRequestProperty("Authorization","Bearer "+token);c.setRequestProperty("X-AVR-Token",token);}
        int code=c.getResponseCode();if(code<200||code>=300)throw new IOException("HTTP "+code);
        try(InputStream is=c.getInputStream();ByteArrayOutputStream out=new ByteArrayOutputStream()){
            byte[] buffer=new byte[8192];int n;while((n=is.read(buffer))>0)out.write(buffer,0,n);return out.toByteArray();
        }
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
