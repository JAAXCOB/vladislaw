package ru.avrescue.app;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/** Small dependency-free encrypted store backed by a non-exportable Android Keystore key. */
public final class SecureStore {
    private static final String PREFS="avr_secure";
    private static final String ALIAS="av_rescue_local_secrets_v1";
    private SecureStore(){}
    public static synchronized void put(Context context,String name,String value){try{Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,key());byte[] encrypted=cipher.doFinal((value==null?"":value).getBytes(StandardCharsets.UTF_8));String packed=Base64.encodeToString(cipher.getIV(),Base64.NO_WRAP)+"."+Base64.encodeToString(encrypted,Base64.NO_WRAP);if(!prefs(context).edit().putString(name,packed).commit())throw new IllegalStateException("secure storage write failed");}catch(Exception e){throw new IllegalStateException("Не удалось защитить локальные данные",e);}}
    public static synchronized String get(Context context,String name){String packed=prefs(context).getString(name,"");if(packed==null||packed.isEmpty())return "";try{String[] parts=packed.split("\\.",2);if(parts.length!=2)throw new IllegalArgumentException();Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.DECRYPT_MODE,key(),new GCMParameterSpec(128,Base64.decode(parts[0],Base64.NO_WRAP)));return new String(cipher.doFinal(Base64.decode(parts[1],Base64.NO_WRAP)),StandardCharsets.UTF_8);}catch(Exception e){prefs(context).edit().remove(name).commit();return "";}}
    public static synchronized void remove(Context context,String name){prefs(context).edit().remove(name).commit();}
    private static SharedPreferences prefs(Context c){return c.getApplicationContext().getSharedPreferences(PREFS,Context.MODE_PRIVATE);}
    private static SecretKey key() throws Exception{KeyStore store=KeyStore.getInstance("AndroidKeyStore");store.load(null);java.security.Key existing=store.getKey(ALIAS,null);if(existing instanceof SecretKey)return (SecretKey)existing;KeyGenerator generator=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");generator.init(new KeyGenParameterSpec.Builder(ALIAS,KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).setRandomizedEncryptionRequired(true).build());return generator.generateKey();}
}
