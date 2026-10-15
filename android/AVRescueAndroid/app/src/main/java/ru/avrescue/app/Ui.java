package ru.avrescue.app;

import android.graphics.Color;
import android.graphics.Typeface;
import android.widget.*;
import android.content.Context;

public final class Ui {
 public static final int BG=Color.rgb(8,9,10), PANEL=Color.rgb(17,19,23), RED=Color.rgb(237,17,28), WHITE=Color.WHITE, MUTED=Color.rgb(174,179,186), GREEN=Color.rgb(53,208,127), BLUE=Color.rgb(67,138,245);

 public static TextView text(Context c,String s,int sp,boolean bold){
   TextView v=new TextView(c); v.setText(s); v.setTextColor(WHITE); v.setTextSize(sp);
   if(bold)v.setTypeface(Typeface.DEFAULT,Typeface.BOLD); v.setPadding(0,8,0,8); return v;
 }
 public static TextView muted(Context c,String s,int sp){TextView v=text(c,s,sp,false);v.setTextColor(MUTED);return v;}
 public static EditText input(Context c,String hint){
   EditText e=new EditText(c);e.setHint(hint);e.setHintTextColor(Color.GRAY);e.setTextColor(WHITE);
   e.setBackgroundResource(R.drawable.input);e.setPadding(dp(c,14),dp(c,12),dp(c,14),dp(c,12));
   LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));e.setLayoutParams(p);return e;
 }
 public static Button button(Context c,String s){
   Button b=new Button(c);b.setText(s);b.setTextColor(WHITE);b.setAllCaps(false);b.setTypeface(Typeface.DEFAULT,Typeface.BOLD);
   b.setBackgroundResource(R.drawable.button_red);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));b.setLayoutParams(p);return b;
 }
 public static Button darkButton(Context c,String s){Button b=button(c,s);b.setBackgroundResource(R.drawable.button_dark);return b;}
 public static Button primaryButton(Context c,String s){Button b=button(c,s);b.setTextSize(17);b.setMinHeight(dp(c,58));return b;}
 public static Button tabButton(Context c,String s,boolean selected,Runnable action){Button b=selected?button(c,s):darkButton(c,s);b.setTextSize(12);b.setMinHeight(dp(c,48));b.setOnClickListener(v->action.run());return b;}
 public static LinearLayout column(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,20),dp(c,16),dp(c,20),dp(c,24));l.setBackgroundColor(BG);return l;}
 public static LinearLayout card(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,16),dp(c,14),dp(c,16),dp(c,14));l.setBackgroundResource(R.drawable.panel);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));l.setLayoutParams(p);return l;}
 public static TextView brand(Context c){return text(c,"AVR  Rescue",30,true);}
 public static TextView chip(Context c,String s,boolean good){TextView v=text(c,s,12,true);v.setTextColor(good?GREEN:MUTED);return v;}
 public static LinearLayout routePoint(Context c,String point,String address,boolean destination){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,14),dp(c,12),dp(c,14),dp(c,12));l.setBackgroundResource(destination?R.drawable.point_b:R.drawable.point_a);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,6),0,dp(c,6));l.setLayoutParams(p);TextView label=text(c,(destination?"Б  ":"А  ")+point,13,true);label.setTextColor(destination?GREEN:BLUE);l.addView(label);l.addView(text(c,address==null||address.trim().isEmpty()?"Адрес не указан":address.trim(),17,true));return l;}
 public static LinearLayout workProgress(Context c,String status){String[] codes={"assigned","enroute","arrived","loading","in_transit","done"};String[] labels={"Заявка принята","Маршрут к точке А","На месте","Погрузка","Маршрут к точке Б","Завершено"};int current=0;for(int i=0;i<codes.length;i++)if(codes[i].equals(status)||("completed".equals(status)&&"done".equals(codes[i])))current=i;LinearLayout l=card(c);l.addView(text(c,"Этапы работы",18,true));for(int i=0;i<labels.length;i++){TextView step=text(c,(i<current?"✓  ":i==current?"●  ":"○  ")+labels[i],14,i==current);step.setTextColor(i<current?GREEN:i==current?WHITE:MUTED);l.addView(step);}return l;}
 public static int dp(Context c,int n){return (int)(n*c.getResources().getDisplayMetrics().density+.5f);}
}
